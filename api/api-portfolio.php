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
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

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

if (!defined('DB_HOST')) { echo json_encode(['success'=>false, 'error'=>'DB Config Missing']); exit; }

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // 1. Fetch Rates
    $rStmt = $pdo->query("SELECT currency, rate, amount FROM rates WHERE (currency, date) IN (SELECT currency, MAX(date) FROM rates GROUP BY currency)");
    $rates = ['CZK' => 1];
    while($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
        $rates[$r['currency']] = $r['amount'] > 0 ? (float)$r['rate'] / (float)$r['amount'] : 0;
    }
    
    // 2. Fetch Prices
    $quotes = [];
    $stmtQ = $pdo->query("SELECT id, current_price, currency FROM live_quotes WHERE status='active'");
    while($r = $stmtQ->fetch(PDO::FETCH_ASSOC)) {
         $quotes[$r['id']] = ['price'=>(float)$r['current_price'], 'currency'=>$r['currency']];
    }

    // 3. Fetch Transactions
    $sql="SELECT trans_id, date, id, amount, price, ex_rate, currency, amount_czk, platform, product_type, trans_type 
          FROM transactions WHERE user_id = ? ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Aggregate
    // Support groupBy parameter: 'ticker_platform' (default, detailed) or 'ticker' (aggregated)
    $groupBy = $_GET['groupBy'] ?? 'ticker_platform';
    
    $groups = [];
    foreach ($rows as $r) {
        $ticker = $r['id'];
        if(!$ticker) continue;
        // Filter out Cash/Fees from Balance View if they are product_type
        if (in_array($r['product_type'], ['Cash', 'Fee'])) continue; 

        // Create aggregation key based on groupBy parameter
        if ($groupBy === 'ticker') {
            $key = $ticker;
        } else {
            $key = $ticker . '|' . $r['platform'];
        }

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'ticker' => $ticker,
                'currency' => $r['currency'],
                'platform' => $r['platform'],
                'net_qty' => 0.0,
                'total_cost_czk' => 0.0,
                'total_cost_orig' => 0.0
            ];
        }
        $g =& $groups[$key];
        
        $tt = strtolower($r['trans_type']);
        $amount = (float)$r['amount'];
        $amountCzk = (float)$r['amount_czk'];
        $price = (float)$r['price'];
        
        if ($tt === 'buy') {
            $g['net_qty'] += $amount;
            $g['total_cost_czk'] += abs($amountCzk);
            $g['total_cost_orig'] += ($amount * $price); 
        } elseif ($tt === 'sell') {
            if ($g['net_qty'] > 0) {
                 $ratio = $amount / $g['net_qty'];
                 // Cap ratio at 1
                 if ($ratio > 1) $ratio = 1;
                 
                 $g['total_cost_czk'] -= ($g['total_cost_czk'] * $ratio);
                 $g['total_cost_orig'] -= ($g['total_cost_orig'] * $ratio);
            }
            $g['net_qty'] -= $amount;
        } elseif ($tt === 'revenue') { 
             $g['net_qty'] += $amount;
             $g['total_cost_czk'] += abs($amountCzk);
             $g['total_cost_orig'] += ($amount * $price);
        } elseif ($tt === 'deposit') {
             $g['net_qty'] += $amount;
             $g['total_cost_czk'] += abs($amountCzk);
             $g['total_cost_orig'] += ($amount * $price);
        } elseif ($tt === 'withdrawal') {
            if ($g['net_qty'] > 0) {
                 $ratio = $amount / $g['net_qty'];
                 if ($ratio > 1) $ratio = 1;
                 $g['total_cost_czk'] -= ($g['total_cost_czk'] * $ratio);
                 $g['total_cost_orig'] -= ($g['total_cost_orig'] * $ratio);
            }
            $g['net_qty'] -= $amount;
        }
        unset($g);
    }
    
    // 5. Finalize
    $finalList = [];
    $summary = ['total_value_czk' => 0, 'total_cost_czk' => 0, 'total_unrealized_czk' => 0, 'count' => 0];
    
    foreach ($groups as $key => $g) {
        if ($g['net_qty'] <= 0.0001) continue;
        
        $g['avg_cost_czk'] = $g['net_qty'] > 0 ? $g['total_cost_czk'] / $g['net_qty'] : 0;
        $g['avg_cost_orig'] = $g['net_qty'] > 0 ? $g['total_cost_orig'] / $g['net_qty'] : 0;
        
        $currentPrice = 0;
        $currencyMismatch = false;
        if (isset($quotes[$g['ticker']])) {
            $quote = $quotes[$g['ticker']];
            $currentPrice = $quote['price'];
            // DON'T override currency from transactions - keep transaction currency as source of truth
            // But log if there's a mismatch for debugging
            if ($quote['currency'] && $quote['currency'] !== $g['currency']) {
                $currencyMismatch = true;
                // error_log("Currency mismatch for $key: transactions say {$g['currency']}, live_quotes says {$quote['currency']}");
            }
        }
        $g['current_price'] = $currentPrice;
        $g['currency_mismatch'] = $currencyMismatch;
        
        $cur = $g['currency'];
        $rate = isset($rates[$cur]) ? $rates[$cur] : 1;
        
        $g['current_price_czk'] = $currentPrice * $rate;
        $g['current_value_czk'] = $g['net_qty'] * $g['current_price_czk'];
        
        $g['unrealized_czk'] = $g['current_value_czk'] - $g['total_cost_czk'];
        $g['unrealized_pct'] = $g['total_cost_czk'] > 0 ? ($g['unrealized_czk'] / $g['total_cost_czk']) * 100 : 0;
        
        // Orig P&L
        $g['current_value_orig'] = $g['net_qty'] * $currentPrice;
        $g['unrealized_orig'] = $g['current_value_orig'] - $g['total_cost_orig'];
        $g['unrealized_pct_orig'] = $g['total_cost_orig'] > 0 ? ($g['unrealized_orig'] / $g['total_cost_orig']) * 100 : 0;

        // FX P&L approximation
        // Basic P&L (CZK) = Total Unrealized CZK
        // Price P&L (Translated) = (Price - AvgPriceOrig) * Rate * Qty = UnrealizedOrig * Rate
        $pricePnL_Translated = $g['unrealized_orig'] * $rate;
        $g['fx_pnl_czk'] = $g['unrealized_czk'] - $pricePnL_Translated;

        $summary['total_value_czk'] += $g['current_value_czk'];
        $summary['total_cost_czk'] += $g['total_cost_czk'];
        $summary['total_unrealized_czk'] += $g['unrealized_czk'];
        $summary['count']++;
        
        $finalList[] = $g;
    }
    
    // Sort by Value DESC
    usort($finalList, function($a, $b) {
        return $b['current_value_czk'] <=> $a['current_value_czk'];
    });

    echo json_encode(['success'=>true, 'data'=>$finalList, 'summary'=>$summary]);

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
