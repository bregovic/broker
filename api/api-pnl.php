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
    $sql = "SELECT trans_id, date, ticker, amount, price, ex_rate, currency, amount_cur, amount_czk, platform, fees
            FROM transactions 
            WHERE user_id = ?
            AND UPPER(trans_type) = 'SELL'
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
        'fx_total' => 0,
        'fees_total' => 0,
        'tax_free_profit' => 0,
        'taxable_profit' => 0,
        'winning' => 0,
        'losing' => 0,
        'total_count' => 0
    ];

    foreach ($sales as $sale) {
        $ticker = $sale['ticker'];
        
        // Helper calculation for average price (Postgres friendly)
        $sqlBuy = "SELECT date, amount, price, amount_cur, amount_czk, ex_rate, fees
                   FROM transactions
                   WHERE user_id = ? AND ticker = ? AND UPPER(trans_type) = 'BUY'
                   AND date <= ? AND platform = ?
                   ORDER BY date ASC";
        $stmtB = $pdo->prepare($sqlBuy);
        $stmtB->execute([$userId, $ticker, $sale['date'], $sale['platform']]);
        $purchases = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        $totalBought = 0; $totalCostCZK = 0; $totalCostCur = 0; $totalBuyFeesCzk = 0; $firstDate = null;
        foreach ($purchases as $p) {
            $totalBought    += (float)$p['amount'];
            $totalCostCZK   += abs((float)$p['amount_czk']);
            $totalCostCur   += abs((float)$p['amount_cur']);
            $totalBuyFeesCzk += abs((float)($p['fees'] ?? 0)) * (float)($p['ex_rate'] ?: 1);
            if (!$firstDate) $firstDate = $p['date'];
        }

        $sellQty     = abs((float)$sale['amount']);
        $proceedsCzk = abs((float)$sale['amount_czk']);
        $proceedsCur = abs((float)$sale['amount_cur']);
        $sellRate    = $proceedsCur > 0 ? $proceedsCzk / $proceedsCur : (float)($sale['ex_rate'] ?: 1);

        // Average cost basis (CZK and original currency) for the sold quantity
        $costCzk = $totalBought > 0 ? ($totalCostCZK / $totalBought) * $sellQty : 0;
        $costCur = $totalBought > 0 ? ($totalCostCur / $totalBought) * $sellQty : 0;
        $buyRate = $costCur > 0 ? $costCzk / $costCur : $sellRate;

        // Fees in CZK: sell fees (at sell rate) + buy fees allocated to the sold portion
        $feesCzk = abs((float)($sale['fees'] ?? 0)) * $sellRate
                 + ($totalBought > 0 ? $totalBuyFeesCzk * ($sellQty / $totalBought) : 0);

        // Decompose the realized result (CZK):
        //   gross = proceeds - cost (incl. FX, before fees)
        //   fx    = foreign proceeds * (sell rate - buy rate)  -> currency-move part
        //   net   = gross - fees
        $profitCZK = $proceedsCzk - $costCzk;
        $fxCZK     = $proceedsCur * ($sellRate - $buyRate);
        $netCZK    = $profitCZK - $feesCzk;

        // Tax test (3 years)
        $taxTestPassed = false;
        if ($firstDate) {
            $d1 = new DateTime($firstDate);
            $d2 = new DateTime($sale['date']);
            $diff = $d1->diff($d2);
            $taxTestPassed = ($diff->days >= 1095);
        }

        $stats['total_count']++;
        if ($netCZK >= 0) { $stats['realized_profit'] += $netCZK; $stats['winning']++; }
        else { $stats['realized_loss'] += abs($netCZK); $stats['losing']++; }
        $stats['fx_total']   += $fxCZK;
        $stats['fees_total'] += $feesCzk;
        if ($taxTestPassed) $stats['tax_free_profit'] += $netCZK;
        else $stats['taxable_profit'] += $netCZK;
        $stats['net_profit'] += $netCZK;
        
        $data[] = [
            'id' => $sale['trans_id'],
            'date' => $sale['date'],
            'ticker' => $ticker,
            'qty' => $sellQty,
            'profit_czk' => $profitCZK,
            'fx_czk' => $fxCZK,
            'fees_czk' => $feesCzk,
            'net_profit_czk' => $netCZK,
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
