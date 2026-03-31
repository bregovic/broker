<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return 0;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

try {
    $pdo = get_pdo();

    // 1. Fetch Sales (ticker instead of id)
    $sql = "SELECT trans_id, date, ticker, amount, price, ex_rate, currency, amount_czk, platform, fees
            FROM transactions 
            WHERE user_id = ? 
            AND trans_type = 'Sell' 
            AND (product_type = 'Stock' OR product_type = 'Crypto') 
            ORDER BY date DESC, trans_id DESC LIMIT 2000";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Fetch Years (Postgres version)
    $stmtYears = $pdo->prepare("SELECT DISTINCT EXTRACT(YEAR FROM date) as yr FROM transactions WHERE user_id=? ORDER BY 1 DESC");
    $stmtYears->execute([$userId]);
    $years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
    
    $data = [];
    $stats = [
        'net_profit' => 0,
        'realized_profit' => 0,
        'realized_loss' => 0,
        'tax_free_profit' => 0,
        'taxable_profit' => 0,
        'winning' => 0,
        'losing' => 0,
        'total_count' => 0
    ];

    foreach ($sales as $sale) {
        $ticker = $sale['ticker'];
        
        // Helper calculation for average price (Postgres friendly)
        $sqlBuy = "SELECT date, amount, price, amount_czk, ex_rate 
                   FROM transactions 
                   WHERE user_id = ? AND ticker = ? AND trans_type = 'Buy' 
                   AND date <= ? AND platform = ?
                   ORDER BY date ASC";
        $stmtB = $pdo->prepare($sqlBuy);
        $stmtB->execute([$userId, $ticker, $sale['date'], $sale['platform']]);
        $purchases = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        $totalBought = 0; $totalCostCZK = 0; $firstDate = null;
        foreach ($purchases as $p) {
            $totalBought += (float)$p['amount'];
            $totalCostCZK += abs((float)$p['amount_czk']);
            if (!$firstDate) $firstDate = $p['date'];
        }

        $avgPriceCZK = $totalBought > 0 ? $totalCostCZK / $totalBought : 0;
        $sellQty = (float)$sale['amount'];
        $sellPriceCZK = $sellQty > 0 ? abs((float)$sale['amount_czk']) / $sellQty : 0;
        $profitCZK = ($sellPriceCZK - $avgPriceCZK) * $sellQty;
        
        // Tax test (3 years)
        $taxTestPassed = false;
        if ($firstDate) {
            $d1 = new DateTime($firstDate);
            $d2 = new DateTime($sale['date']);
            $diff = $d1->diff($d2);
            $taxTestPassed = ($diff->days >= 1095);
        }
        
        $stats['total_count']++;
        if ($profitCZK > 0) {
            $stats['realized_profit'] += $profitCZK;
            $stats['winning']++;
        } else {
            $stats['realized_loss'] += abs($profitCZK);
            $stats['losing']++;
        }
        
        if ($taxTestPassed) $stats['tax_free_profit'] += $profitCZK;
        else $stats['taxable_profit'] += $profitCZK;
        
        $stats['net_profit'] += ($profitCZK - (float)$sale['fees']);
        
        $data[] = [
            'id' => $sale['trans_id'],
            'date' => $sale['date'],
            'ticker' => $ticker,
            'qty' => $sellQty,
            'profit_czk' => $profitCZK,
            'net_profit_czk' => $profitCZK - (float)$sale['fees'],
            'tax_test' => $taxTestPassed,
            'holding_days' => $firstDate ? (new DateTime($firstDate))->diff(new DateTime($sale['date']))->days : 0,
            'platform' => $sale['platform'],
            'currency' => $sale['currency']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'data' => $data,
        'years' => $years
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()]);
}
