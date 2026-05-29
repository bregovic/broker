<?php
/**
 * On-demand CZK FX rate sync.
 *
 * Pulls the ČNB daily fixing into the `rates` table, but only when the newest
 * stored rate is stale — so a normal page load reuses the cached value and only
 * the first request of the day hits ČNB. Fails silently (page keeps working with
 * whatever rates already exist) if ČNB is unreachable.
 */
function ensure_current_rates(PDO $pdo): void {
    try {
        $latest = $pdo->query("SELECT MAX(date) FROM rates")->fetchColumn();
    } catch (Exception $e) {
        return; // rates table unavailable — nothing to do
    }

    // ČNB doesn't publish on weekends/holidays, so treat the last ~3 days as fresh.
    if ($latest && $latest >= date('Y-m-d', strtotime('-3 days'))) {
        return;
    }

    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt';
    $ctx = stream_context_create(['http' => ['timeout' => 5], 'https' => ['timeout' => 5]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || trim($body) === '') return;

    $lines = explode("\n", trim($body));
    if (count($lines) < 3) return;

    // Line 0: "29.05.2026 #103"  |  Line 1: header  |  Line 2+: data
    $rateDate = date('Y-m-d');
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $lines[0], $m)) {
        $rateDate = "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    $stmt = $pdo->prepare(
        "INSERT INTO rates (date, currency, rate, amount) VALUES (?, ?, ?, ?)
         ON CONFLICT (currency, date) DO UPDATE SET rate = EXCLUDED.rate, amount = EXCLUDED.amount"
    );

    for ($i = 2; $i < count($lines); $i++) {
        // Format: země|měna|množství|kód|kurz   (e.g. "USA|dolar|1|USD|21,055")
        $parts = explode('|', trim($lines[$i]));
        if (count($parts) < 5) continue;
        $amount = (int) $parts[2];
        $code   = strtoupper(trim($parts[3]));
        $rate   = (float) str_replace([' ', ','], ['', '.'], $parts[4]);
        if ($code === '' || $rate <= 0 || $amount <= 0) continue;
        try { $stmt->execute([$rateDate, $code, $rate, $amount]); } catch (Exception $e) {}
    }
}
