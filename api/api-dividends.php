<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. Resolve User ID
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
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized', 'debug_session'=>$_SESSION]);
    exit;
}

// 2. DB Connect
$paths = [
    __DIR__.'/env.local.php', 
    __DIR__.'/php/env.local.php', 
    __DIR__.'/../env.local.php', 
    __DIR__.'/../../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__.'/env.php',
    __DIR__.'/../env.php',
    __DIR__.'/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];
foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }

if (!defined('DB_HOST')) {
    echo json_encode(['success'=>false, 'error'=>'DB Config Missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'Database connection failed: '.$e->getMessage()]);
    exit;
}

// 3. Filters
// Simplifying for API: Default fetch all dividends/tax for the user. Client can filter/sort.
// Or we can implement basic server-side filtering if needed. For now, fetch ALL (Limit 5000)

try {
    // Determine year range for filter dropdown
    $stmtYears = $pdo->prepare("SELECT DISTINCT YEAR(date) as year FROM transactions WHERE user_id=? AND (trans_type='Dividend' OR trans_type='Withholding') ORDER BY year DESC");
    $stmtYears->execute([$userId]);
    $years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);

    // Fetch Data
    $sql = "SELECT trans_id, date, id as ticker, amount_cur, currency, ex_rate, amount_czk, platform, trans_type, notes 
            FROM transactions 
            WHERE user_id = ? 
            AND (trans_type='Dividend' OR trans_type='Withholding') 
            ORDER BY date DESC LIMIT 5000";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    // Calculate Summary Stats (Server-side)
    $stats = [
        'total_div_czk' => 0,
        'total_tax_czk' => 0,
        'total_net_czk' => 0,
        'by_currency' => [], // { 'USD': { div: 0, tax: 0, net: 0 } }
        'count' => 0
    ];

    foreach($rows as $r) {
        $cur = $r['currency'];
        if (!isset($stats['by_currency'][$cur])) {
            $stats['by_currency'][$cur] = ['div'=>0, 'tax'=>0, 'net'=>0];
        }

        if ($r['trans_type'] === 'Dividend') {
            $stats['count']++;
            $stats['total_div_czk'] += (float)$r['amount_czk'];
            $stats['by_currency'][$cur]['div'] += (float)$r['amount_cur'];
        } elseif ($r['trans_type'] === 'Withholding') {
            // Tax is usually negative in DB? Or positive?
            // div.php uses abs(). Let's check DB content. 
            // Usually Withholding is negative amount.
            // But let's assume we sum raw amount. 
            // In div.php: $totalStats['total_tax_czk'] += abs((float)$r['amount_czk']);
            // So if DB has negative, check that.
            // I'll sum RAW amounts for Net.
            // For Display "Total Tax", I'll verify if negative.
            
            $valCzk = (float)$r['amount_czk'];
            $valCur = (float)$r['amount_cur'];
            
            // Tax is usually negative.
            // Implementation: Sum absolute for "Tax Paid".
            $stats['total_tax_czk'] += abs($valCzk);
            $stats['by_currency'][$cur]['tax'] += abs($valCur);
        }
    }
    $stats['total_net_czk'] = $stats['total_div_czk'] - $stats['total_tax_czk'];

    // Provide simplified rows for frontend
    $data = array_map(function($r){
        return [
            'id' => (int)$r['trans_id'],
            'date' => $r['date'],
            'ticker' => $r['ticker'],
            'type' => $r['trans_type'],
            'amount' => (float)$r['amount_cur'],
            'currency' => $r['currency'],
            'amount_czk' => (float)$r['amount_czk'],
            'platform' => $r['platform'],
            'notes' => $r['notes']
        ];
    }, $rows);

    echo json_encode([
        'success' => true,
        'filters' => ['years' => $years],
        'stats' => $stats,
        'data' => $data
    ]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
