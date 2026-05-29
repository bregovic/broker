<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) { foreach ($candidates as $k) if (isset($u[$k]) && is_numeric($u[$k])) return (int)$u[$k]; }
        elseif (is_object($u)) { foreach ($candidates as $k) if (isset($u->$k) && is_numeric($u->$k)) return (int)$u->$k; }
    }
    return 0;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'DB Connection failed']);
    exit;
}

// In the database, the table is called transactions (or broker_trans view).
// We retrieve transactions where trans_type is 'Dividend' or 'Withholding'.
$sql = "SELECT 
            trans_id AS id, 
            date, 
            id AS ticker, 
            trans_type AS type, 
            amount_cur AS amount, 
            currency, 
            amount_czk, 
            platform, 
            COALESCE(notes, '') AS notes 
        FROM transactions 
        WHERE user_id = ? AND trans_type IN ('Dividend', 'Withholding') 
        ORDER BY date DESC, trans_id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalize data (ensure proper casting to numbers, matching React page requirements)
    $normalizedRows = [];
    $total_div_czk = 0.0;
    $total_tax_czk = 0.0;
    $byCurrency = [];

    foreach ($rows as $row) {
        $item = [
            'id' => (int)$row['id'],
            'date' => $row['date'],
            'ticker' => strtoupper(trim($row['ticker'])),
            'type' => $row['type'],
            'amount' => (float)$row['amount'],
            'currency' => strtoupper(trim($row['currency'])),
            'amount_czk' => (float)$row['amount_czk'],
            'platform' => $row['platform'],
            'notes' => $row['notes']
        ];
        $normalizedRows[] = $item;

        $val = abs($item['amount_czk']);
        $cur = $item['currency'];
        if (!isset($byCurrency[$cur])) {
            $byCurrency[$cur] = ['div' => 0.0, 'tax' => 0.0];
        }

        if ($item['type'] === 'Dividend') {
            $total_div_czk += $val;
            $byCurrency[$cur]['div'] += abs($item['amount']);
        } elseif ($item['type'] === 'Withholding') {
            $total_tax_czk += $val;
            $byCurrency[$cur]['tax'] += abs($item['amount']);
        }
    }

    $total_net_czk = $total_div_czk - $total_tax_czk;

    $stats = [
        'total_div_czk' => $total_div_czk,
        'total_tax_czk' => $total_tax_czk,
        'total_net_czk' => $total_net_czk,
        'count' => count($normalizedRows),
        'by_currency' => $byCurrency
    ];

    echo json_encode([
        'success' => true, 
        'data' => $normalizedRows,
        'stats' => $stats
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
?>
