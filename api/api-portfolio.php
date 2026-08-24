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
    // Make sure we have a current CZK fixing (pulls ČNB on-demand if stale).
    require_once __DIR__ . '/rate_sync.php';
    ensure_current_rates($pdo);

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
        $sql="SELECT trans_id, date, COALESCE(a.canonical, tr.ticker) AS ticker, amount, price, ex_rate, currency, amount_cur, amount_czk, platform, product_type, trans_type
              FROM transactions tr LEFT JOIN ticker_aliases a ON a.alias = tr.ticker
              WHERE tr.user_id = ? ORDER BY date ASC";
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
                'total_cost_orig' => 0.0,
                'currencies' => []
            ];
        }
        $g =& $groups[$key];
        $tt = strtolower($r['trans_type']);
        $amount = (float)$r['amount'];
        $amountCzk = (float)$r['amount_czk'];
        // Cost in the original currency comes from the same field as amount_czk (amount_czk =
        // amount_cur * ex_rate), so the two cost bases stay consistent and the FX split is exact.
        // amount * price is only a fallback for parsers that leave amount_cur empty.
        $amountCur = abs((float)$r['amount_cur']);
        if ($amountCur <= 0) $amountCur = abs($amount * (float)$r['price']);

        if ($tt === 'buy' || $tt === 'revenue' || $tt === 'deposit') {
            $g['net_qty'] += $amount;
            $g['total_cost_czk'] += abs($amountCzk);
            $g['total_cost_orig'] += $amountCur;
            if (!empty($r['currency'])) $g['currencies'][strtoupper($r['currency'])] = true;
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
        $quoteCurrency = null;
        if (isset($quotes[$g['ticker']])) {
            $currentPrice = $quotes[$g['ticker']]['price'];
            // A quote can be denominated differently than the transactions (e.g. IBKR books
            // US stocks in CZK). Trust the quote's own currency when we can convert it.
            $qc = strtoupper(trim((string)$quotes[$g['ticker']]['currency']));
            if ($qc !== '' && !empty($rates[$qc])) $quoteCurrency = $qc;
        }

        $costCurrency = strtoupper((string)$g['currency']);
        $costRate = (float)($rates[$costCurrency] ?? 0);          // CZK per 1 unit of cost currency
        $quoteRate = $quoteCurrency !== null ? (float)$rates[$quoteCurrency] : $costRate;
        if ($quoteRate <= 0) $quoteRate = 1;

        $g['current_price'] = $currentPrice;
        $g['current_price_czk'] = $currentPrice * $quoteRate;
        $g['current_value_czk'] = $g['net_qty'] * $g['current_price_czk'];
        $g['unrealized_czk'] = $g['current_value_czk'] - $g['total_cost_czk'];
        $g['unrealized_pct'] = $g['total_cost_czk'] > 0 ? ($g['unrealized_czk'] / $g['total_cost_czk']) * 100 : 0;
        $g['avg_cost_czk'] = $g['net_qty'] > 0 ? $g['total_cost_czk'] / $g['net_qty'] : null;

        // Original-currency view. Only meaningful when the whole position shares one cost
        // currency — grouping by ticker alone can merge platforms that book in different ones.
        if (count($g['currencies']) > 1 || $costRate <= 0) {
            $g['avg_cost_orig'] = null;
            $g['unrealized_orig'] = null;
            $g['unrealized_pct_orig'] = null;
            $g['fx_pnl_czk'] = null;
        } else {
            $avgCostOrig = $g['total_cost_orig'] / $g['net_qty'];
            $currentPriceOrig = $g['current_price_czk'] / $costRate;
            $unrealizedOrig = ($currentPriceOrig - $avgCostOrig) * $g['net_qty'];

            $g['avg_cost_orig'] = $avgCostOrig;
            $g['unrealized_orig'] = $unrealizedOrig;
            $g['unrealized_pct_orig'] = $g['total_cost_orig'] > 0 ? ($unrealizedOrig / $g['total_cost_orig']) * 100 : 0;
            // Split the CZK result: the price move valued at today's rate, and the remainder,
            // which is what the currency itself did to the cost basis.
            $g['fx_pnl_czk'] = $g['unrealized_czk'] - ($unrealizedOrig * $costRate);
        }
        unset($g['currencies']);

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
