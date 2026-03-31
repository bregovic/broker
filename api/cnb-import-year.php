<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

session_start();

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
if ($year < 1991 || $year > (int)date('Y') + 1) {
    echo json_encode(['success' => false, 'message' => 'Invalid year: ' . $year]);
    exit;
}

try {
    $pdo = get_pdo();

    $url = 'https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/rok.txt?rok=' . $year;
    
    $body = @file_get_contents($url);
    if (!$body) {
        throw new Exception("Could not fetch data from CNB: " . $url);
    }

    $body = trim($body);
    $body = str_replace("\r\n", "\n", $body);
    $lines = explode("\n", $body);

    if (count($lines) < 2) {
        throw new Exception("Unexpected CNB data format.");
    }

    $header = explode('|', $lines[0]);
    $colsCount = count($header);
    $colMap = []; 

    for ($i = 1; $i < $colsCount; $i++) {
        $h = trim($header[$i]);
        $h = preg_replace('/\s+/', ' ', $h);
        if (preg_match('/^(\d+)\s+([A-Z]{3})$/', $h, $m)) {
            $colMap[$i] = ['amount' => (int)$m[1], 'currency' => $m[2]];
        }
    }

    $sql = "INSERT INTO rates (date, currency, rate, amount)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (currency, date) DO UPDATE 
            SET rate = EXCLUDED.rate, amount = EXCLUDED.amount";
    $stmt = $pdo->prepare($sql);

    $inserted = 0;
    $pdo->beginTransaction();

    for ($lineIdx = 1; $lineIdx < count($lines); $lineIdx++) {
        $line = trim($lines[$lineIdx]);
        if ($line === '') continue;
        $parts = explode('|', $line);
        if (count($parts) < 2) continue;

        $dateStr = trim($parts[0]);
        $dt = DateTime::createFromFormat('d.m.Y', $dateStr);
        if (!$dt) continue;
        $dateDb = $dt->format('Y-m-d');

        for ($i = 1; $i < min(count($parts), $colsCount); $i++) {
            if (!isset($colMap[$i])) continue;
            $raw = trim($parts[$i]);
            if ($raw === '' || $raw === '-') continue;
            
            $rate = (float)str_replace([' ', ','], ['', '.'], $raw);
            $currency = $colMap[$i]['currency'];
            $amount   = $colMap[$i]['amount'];

            $stmt->execute([$dateDb, $currency, $rate, $amount]);
            $inserted++;
        }
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'inserted' => $inserted, 'year' => $year]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
