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

try {
    // 1. Fetch Rates (Robustly)
    $rates = ['CZK' => 1];
    try {
        $rStmt = $pdo->query("SELECT r.currency, r.rate, r.amount FROM rates r 
                             INNER JOIN (SELECT currency, MAX(date) as max_date FROM rates GROUP BY currency) m 
                             ON r.currency = m.currency AND r.date = m.max_date");
        if ($rStmt) {
            while($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
                $rates[$r['currency']] = (float)$r['amount'] > 0 ? (float)$r['rate'] / (float)$r['amount'] : 0;
            }
        }
    } catch (Throwable $e) { 
        // Silently continue with default 1:1 if rates fail
    }
    
    // 2. Fetch Prices
    $quotes = [];
    try {
        $stmtQ = $pdo->query("SELECT ticker, price, currency FROM live_quotes");
        if ($stmtQ) {
            while($r = $stmtQ->fetch(PDO::FETCH_ASSOC)) {
                 $quotes[$r['ticker']] = ['price'=>(float)$r['price'], 'currency'=>$r['currency']];
            }
        }
    } catch (Throwable $e) {}

    // 3. Fetch Transactions
    try {
        $sql="SELECT trans_id, date, ticker, amount, price, ex_rate, currency, amount_czk, platform, product_type, trans_type 
              FROM transactions WHERE user_id = ? ORDER BY date ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        throw $e; // Re-throw to be caught by the main block
    }
    
    // 4. Aggregate
    $groupBy = $_GET['groupBy'] ?? 'ticker_platform';
    $groups = [];
    foreach ($rows as $r) {
        $ticker = $r['ticker'];
        if(!$ticker) continue;
        if (in_array($r['product_type'], ['Cash', 'Fee'])) continue; 

        $key = ($groupBy === 'ticker') ? $ticker : ($ticker . '|' . $r['platform']);

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
        
        if ($tt === 'buy' || $tt === 'revenue' || $tt === 'deposit') {
            $g['net_qty'] += $amount;
            $g['total_cost_czk'] += abs($amountCzk);
            $g['total_cost_orig'] += ($amount * (float)$r['price']); 
        } elseif ($tt === 'sell' || $tt === 'withdrawal') {
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
    
    foreach ($groups as $g) {
        if ($g['net_qty'] <= 0.0001) continue;
        
        $currentPrice = 0;
        if (isset($quotes[$g['ticker']])) {
            $currentPrice = $quotes[$g['ticker']]['price'];
        }
        
        $rate = $rates[$g['currency']] ?? 1;
        $g['current_price'] = $currentPrice;
        $g['current_price_czk'] = $currentPrice * $rate;
        $g['current_value_czk'] = $g['net_qty'] * $g['current_price_czk'];
        $g['unrealized_czk'] = $g['current_value_czk'] - $g['total_cost_czk'];
        $g['unrealized_pct'] = $g['total_cost_czk'] > 0 ? ($g['unrealized_czk'] / $g['total_cost_czk']) * 100 : 0;
        
        $summary['total_value_czk'] += $g['current_value_czk'];
        $summary['total_cost_czk'] += $g['total_cost_czk'];
        $summary['total_unrealized_czk'] += $g['unrealized_czk'];
        $summary['count']++;
        $finalList[] = $g;
    }
    
    usort($finalList, function($a, $b) {
        return $b['current_value_czk'] <=> $a['current_value_czk'];
    });

    echo json_encode(['success'=>true, 'data'=>$finalList, 'summary'=>$summary]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
