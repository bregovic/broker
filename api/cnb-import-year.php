<?php
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@error_reporting(0);

session_start();

// Stejná kontrola jako v rates.php (aby import nejel anonymně)
$isLoggedIn  = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Nepřihlášený uživatel']);
    exit;
}

/**
 * 1) Načtení roku z POSTu + kontrola
 */
$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
if ($year < 1991 || $year > (int)date('Y') + 1) {
    echo json_encode(['ok' => false, 'message' => 'Neplatný rok: ' . $year]);
    exit;
}

/**
 * 2) Připojení k DB (stejná logika jako v rates.php)
 */
$pdo = null;
try {
    $envPaths = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/php/env.local.php',
        '../env.local.php',
        'php/env.local.php',
        '../php/env.local.php'
    ];
    foreach ($envPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
    if (defined('DB_HOST')) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }
} catch (Exception $e) {
    $pdo = null;
}

if (!$pdo) {
    echo json_encode(['ok' => false, 'message' => 'Nepodařilo se připojit k databázi.']);
    exit;
}

/**
 * 3) Stažení rok.txt z ČNB
 */
$url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/rok.txt?rok=' . $year;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_USERAGENT, 'PortfolioTracker/1.0 (+curl)');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

if ($body === false || $httpCode !== 200) {
    echo json_encode([
        'ok'         => false,
        'message'    => 'HTTP chyba: ' . $httpCode,
        'curl_error' => $error,
        'url'        => $url,
    ]);
    exit;
}

/**
 * 4) Parsování TXT formátu rok.txt
 *    1. řádek: hlavička "Datum|1 AUD|1 BGN|..."
 *    další řádky: "DD.MM.RRRR|kurzA|kurzB|..."
 */

$body = trim($body);
$body = str_replace("\r\n", "\n", $body);
$lines = preg_split('/\r\n|\r|\n/', $body);

if (count($lines) < 2) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Neočekávaný formát dat (málo řádků).',
        'url'     => $url,
    ]);
    exit;
}

// --- hlavička: získáme mapu sloupec -> (měna, množství) ---
$header = explode('|', $lines[0]);
$colsCount = count($header);
$colMap = []; // index => ['currency' => 'USD', 'amount' => 1]

for ($i = 1; $i < $colsCount; $i++) {
    $h = trim($header[$i]);
    // typicky "1 USD" nebo "100 JPY"
    $h = preg_replace('/\s+/', ' ', $h);
    if ($h === '') continue;

    if (preg_match('/^(\d+)\s+([A-Z]{3})$/', $h, $m)) {
        $amount   = (int)$m[1];
        $currency = $m[2];
        $colMap[$i] = [
            'amount'   => $amount,
            'currency' => $currency,
        ];
    }
}

// FIX: Detect RUB index to handle missing column in 2022 (Sanctions caused RUB removal from data but kept in header)
$rubIndex = null;
foreach($colMap as $ii => $cc) {
    if ($cc['currency'] === 'RUB') {
        $rubIndex = $ii;
        break;
    }
}

// bezpečnostní kontrola
if (empty($colMap)) {
    echo json_encode([
        'ok'      => false,
        'message' => 'Nepodařilo se rozpoznat hlavičku kurzů z ČNB.',
        'header'  => $header,
    ]);
    exit;
}

/**
 * 5) Připravený INSERT ... ON DUPLICATE KEY UPDATE
 *    broker_exrates (date, currency) má UNIQUE, takže to bude umět update.
 */
$sql = "
    INSERT INTO rates (date, currency, rate, amount, source)
    VALUES (:date, :currency, :rate, :amount, :source)
    ON DUPLICATE KEY UPDATE
        rate       = VALUES(rate),
        amount     = VALUES(amount),
        source     = VALUES(source),
        updated_at = CURRENT_TIMESTAMP
";
$stmt = $pdo->prepare($sql);

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$days     = 0;

try {
    $pdo->beginTransaction();

    // projedeme všechny datové řádky
    for ($lineIdx = 1; $lineIdx < count($lines); $lineIdx++) {
        $line = trim($lines[$lineIdx]);
        if ($line === '') continue;

        $parts = explode('|', $line);
        
        // FIX: Hande missing RUB column (common in 2022) causing left-shift of subsequent currencies (USD getting XDR values)
        if ($rubIndex !== null && count($parts) === $colsCount - 1) {
             // Insert empty placeholder at RUB position to realign columns
             array_splice($parts, $rubIndex, 0, ""); 
        }

        if (count($parts) < 2) continue;

        $dateStr = trim($parts[0]);
        $dt = DateTime::createFromFormat('d.m.Y', $dateStr);
        if (!$dt) {
            $skipped++;
            continue;
        }
        $dateMysql = $dt->format('Y-m-d');
        $days++;

        // jednotlivé měny ve sloupcích
        for ($i = 1; $i < min(count($parts), $colsCount); $i++) {
            if (!isset($colMap[$i])) continue;

            $raw = trim($parts[$i]);
            if ($raw === '' || $raw === '-' ) {
                $skipped++;
                continue;
            }

            // převod "21,345" -> 21.345
            $norm = str_replace([' ', ','], ['', '.'], $raw);
            if (!is_numeric($norm)) {
                $skipped++;
                continue;
            }

            $rate = (float)$norm;
            $currency = $colMap[$i]['currency'];
            $amount   = $colMap[$i]['amount'];

            $stmt->execute([
                ':date'     => $dateMysql,
                ':currency' => $currency,
                ':rate'     => $rate,
                ':amount'   => $amount,
                ':source'   => 'CNB_year',
            ]);

            // rowCount: 1 = insert, 2 = update, 0 = bez změny
            $rc = $stmt->rowCount();
            if ($rc === 1) {
                $inserted++;
            } elseif ($rc === 2) {
                $updated++;
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'       => true,
        'message'  => 'Roční kurzy úspěšně naimportovány.',
        'year'     => $year,
        'url'      => $url,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'days'     => $days,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'ok'      => false,
        'message' => 'Chyba při zápisu do databáze.',
        'error'   => $e->getMessage(),
    ]);
}
