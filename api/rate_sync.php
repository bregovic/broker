<?php
/**
 * CZK FX rate sync helpers (ČNB).
 *
 * All historical fetches use ČNB's SINGLE-CURRENCY endpoint (`vybrane.txt?mena=`),
 * which carries the currency code on every row, so it is immune to the
 * yearly-matrix column shift that corrupted 2022 when RUB was dropped.
 */

/** Parse a ČNB single-currency body -> ['YYYY-MM-DD' => ['rate'=>float,'amount'=>int]]. */
function cnb_parse_currency_body(string $body): array {
    $out = [];
    $lines = explode("\n", trim($body));
    if (count($lines) < 3) return $out;
    $amount = 1;
    if (preg_match('/Mno\x{017e}stv\x{ed}:\s*(\d+)/u', $lines[0], $m)) $amount = (int)$m[1];
    elseif (preg_match('/:\s*(\d+)\s*$/', $lines[0], $m)) $amount = (int)$m[1];
    for ($i = 2; $i < count($lines); $i++) {           // line 0: "Měna…", line 1: "Datum|Kurz"
        $p = explode('|', trim($lines[$i]));
        if (count($p) < 2) continue;
        if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $p[0], $md)) continue;
        $rate = (float) str_replace([' ', ','], ['', '.'], $p[1]);
        if ($rate <= 0) continue;
        $out["{$md[3]}-{$md[2]}-{$md[1]}"] = ['rate' => $rate, 'amount' => $amount > 0 ? $amount : 1];
    }
    return $out;
}

/** Fetch a currency's daily history from ČNB between two d.m.Y dates. */
function cnb_fetch_currency(string $currency, string $fromDmy, string $toDmy): array {
    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/vybrane.txt'
         . '?mena=' . urlencode($currency) . '&od=' . urlencode($fromDmy) . '&do=' . urlencode($toDmy);
    $ctx = stream_context_create(['http' => ['timeout' => 8], 'https' => ['timeout' => 8]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || trim($body) === '') return [];
    return cnb_parse_currency_body($body);
}

/** Upsert rates, savepoint-protected so a failure can't abort a surrounding tx. */
function rates_upsert(PDO $pdo, string $currency, array $rates): int {
    if (!$rates) return 0;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $useSp = ($driver === 'pgsql' && $pdo->inTransaction());
    $sql = ($driver === 'pgsql')
        ? "INSERT INTO rates (date, currency, rate, amount) VALUES (?,?,?,?) ON CONFLICT (currency, date) DO UPDATE SET rate=EXCLUDED.rate, amount=EXCLUDED.amount"
        : "REPLACE INTO rates (date, currency, rate, amount) VALUES (?,?,?,?)";
    $stmt = $pdo->prepare($sql);
    $n = 0;
    foreach ($rates as $d => $r) {
        if ($useSp) { try { $pdo->exec("SAVEPOINT ru"); } catch (Exception $e) { $useSp = false; } }
        try {
            $stmt->execute([$d, $currency, $r['rate'], $r['amount']]);
            if ($useSp) $pdo->exec("RELEASE SAVEPOINT ru");
            $n++;
        } catch (Exception $e) {
            if ($useSp) { try { $pdo->exec("ROLLBACK TO SAVEPOINT ru"); } catch (Exception $e2) {} }
        }
    }
    return $n;
}

/**
 * Self-healing per-unit rate getter: returns CZK per 1 unit of $currency on/just
 * before $date. If we have no rate within ~7 days before $date, it fetches a small
 * window from ČNB, caches it, and retries. Returns null only if ČNB has nothing.
 */
function ensure_rate(PDO $pdo, string $currency, string $date): ?float {
    $currency = strtoupper(trim($currency));
    if ($currency === 'CZK' || $currency === '') return 1.0;
    $date = substr($date, 0, 10);

    $lookup = function () use ($pdo, $currency, $date) {
        $st = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency=? AND date<=? ORDER BY date DESC LIMIT 1");
        $st->execute([$currency, $date]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (float)$row['rate'] > 0) {
            $amt = (float)($row['amount'] ?? 1);
            return $amt > 0 ? (float)$row['rate'] / $amt : (float)$row['rate'];
        }
        return null;
    };

    // Do we already have a close-enough rate?
    try {
        $st = $pdo->prepare("SELECT max(date) FROM rates WHERE currency=? AND date<=?");
        $st->execute([$currency, $date]);
        $have = $st->fetchColumn();
        if ($have && strtotime($have) >= strtotime($date) - 7 * 86400) {
            return $lookup();
        }
    } catch (Exception $e) { /* fall through to fetch */ }

    // Missing -> pull a small window around the date and cache it.
    $from = date('d.m.Y', strtotime($date) - 12 * 86400);
    $to   = date('d.m.Y', strtotime($date) + 3 * 86400);
    rates_upsert($pdo, $currency, cnb_fetch_currency($currency, $from, $to));

    return $lookup();
}

/**
 * On-demand sync of the latest ČNB daily fixing into `rates` (cached: only fetches
 * when the newest stored rate is stale). Used by valuation pages.
 */
function ensure_current_rates(PDO $pdo): void {
    try {
        $latest = $pdo->query("SELECT MAX(date) FROM rates")->fetchColumn();
    } catch (Exception $e) {
        return;
    }
    if ($latest && $latest >= date('Y-m-d', strtotime('-3 days'))) return;

    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';
    $ctx = stream_context_create(['http' => ['timeout' => 5], 'https' => ['timeout' => 5]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || trim($body) === '') return;

    $lines = explode("\n", trim($body));
    if (count($lines) < 3) return;

    $rateDate = date('Y-m-d');
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $lines[0], $m)) $rateDate = "{$m[3]}-{$m[2]}-{$m[1]}";

    $stmt = $pdo->prepare(
        "INSERT INTO rates (date, currency, rate, amount) VALUES (?, ?, ?, ?)
         ON CONFLICT (currency, date) DO UPDATE SET rate = EXCLUDED.rate, amount = EXCLUDED.amount"
    );
    for ($i = 2; $i < count($lines); $i++) {
        $parts = explode('|', trim($lines[$i]));
        if (count($parts) < 5) continue;
        $amount = (int) $parts[2];
        $code   = strtoupper(trim($parts[3]));
        $rate   = (float) str_replace([' ', ','], ['', '.'], $parts[4]);
        if ($code === '' || $rate <= 0 || $amount <= 0) continue;
        try { $stmt->execute([$rateDate, $code, $rate, $amount]); } catch (Exception $e) {}
    }
}
