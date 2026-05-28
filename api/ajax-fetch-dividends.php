<?php
/**
 * ajax-fetch-dividends.php
 * Fetches 5-year historical dividends from Yahoo Finance, reconciling them
 * with user transactions to build a crowdsourced verified dividend catalog.
 */

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
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/setup_dividend_db.php';

try {
    $pdo = get_pdo();
    
    // Ensure database tables and columns are created on-the-fly (self-healing)
    ensure_dividend_db_setup($pdo);

    $ticker = $_GET['ticker'] ?? $_POST['ticker'] ?? '';
    $force = isset($_GET['force']) || isset($_POST['force']);

    if (!$ticker) {
        echo json_encode(['success' => false, 'error' => 'No ticker provided']);
        exit;
    }

    $ticker = strtoupper(trim($ticker));
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    // Resolve Alias
    try {
        $stmt = $pdo->prepare("SELECT alias_of FROM ticker_mapping WHERE ticker = ? AND alias_of != '' LIMIT 1");
        $stmt->execute([$ticker]);
        $alias = $stmt->fetchColumn();
        if ($alias) {
            $ticker = strtoupper($alias);
        }
    } catch (Exception $e) {}

    // Check if we already have cache
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM dividend_history WHERE ticker = ?");
    $stmtCount->execute([$ticker]);
    $cachedCount = (int)$stmtCount->fetchColumn();

    $shouldFetch = $force || ($cachedCount === 0);

    // Map ticker for Yahoo
    function mapTickerToYahooLocal($t) {
        $t = strtoupper(trim($t));
        $stockMap = [
            'CBK' => 'CBK.DE', 
            'VOW3' => 'VOW3.DE',
            'BAS' => 'BAS.DE',
            'SIE' => 'SIE.DE',
            'ALV' => 'ALV.DE',
            'LLOY' => 'LLOY.L',
            'RR' => 'RR.L'
        ];
        if (isset($stockMap[$t])) return $stockMap[$t];
        
        $etfMap = [
            'ZPRV'=>'ZPRV.DE', 'CNDX'=>'CNDX.L', 'CSPX'=>'CSPX.L', 'IWVL'=>'IWVL.L', 
            'VWRA'=>'VWRA.L', 'EQQQ'=>'EQQQ.DE', 'EUNL'=>'EUNL.DE', 'IS3N'=>'IS3N.DE', 
            'SXR8'=>'SXR8.DE', 'RBOT'=>'RBOT.L', 'RENW'=>'RENW.L'
        ];
        if (isset($etfMap[$t])) return $etfMap[$t];
        
        $czStocks = ['CEZ', 'KB', 'MONET', 'ERBAG', 'KOMB', 'PHILIP', 'COLT', 'KOFOL'];
        if (in_array($t, $czStocks)) return $t . '.PR';
        
        return $t;
    }

    $yahooTicker = mapTickerToYahooLocal($ticker);

    if ($shouldFetch) {
        // Fetch 5 years of dividends from Yahoo
        $period1 = time() - (5 * 365 * 24 * 60 * 60);
        $period2 = time();
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($yahooTicker) . 
               "?period1=$period1&period2=$period2&interval=1d&events=div";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $json = curl_exec($ch);
        curl_close($ch);

        if ($json) {
            $decoded = json_decode($json, true);
            $result = $decoded['chart']['result'][0] ?? null;
            if ($result) {
                $currency = $result['meta']['currency'] ?? 'USD';
                $divEvents = $result['events']['dividends'] ?? [];

                // Reconcile GBp to GBP
                if ($currency === 'GBp') {
                    $currency = 'GBP';
                }

                // Insert/update dividends in DB
                foreach ($divEvents as $event) {
                    $exDate = date('Y-m-d', $event['date']);
                    $amount = (float)$event['amount'];

                    // GBp pence adjustment
                    if ($result['meta']['currency'] === 'GBp') {
                        $amount = $amount * 0.01;
                    }

                    if ($driver === 'pgsql') {
                        $sqlUpsert = "INSERT INTO dividend_history (ticker, ex_date, amount, currency, source)
                                     VALUES (?, ?, ?, ?, 'yahoo')
                                     ON CONFLICT (ticker, ex_date) DO UPDATE 
                                     SET amount = EXCLUDED.amount, 
                                         source = CASE WHEN dividend_history.source = 'user_verified' THEN 'user_verified' ELSE 'yahoo' END";
                    } else {
                        $sqlUpsert = "INSERT INTO dividend_history (ticker, ex_date, amount, currency, source)
                                     VALUES (?, ?, ?, ?, 'yahoo')
                                     ON DUPLICATE KEY UPDATE 
                                         amount = VALUES(amount), 
                                         source = CASE WHEN source = 'user_verified' THEN 'user_verified' ELSE 'yahoo' END";
                    }
                    $stmt = $pdo->prepare($sqlUpsert);
                    $stmt->execute([$ticker, $exDate, $amount, $currency]);
                }
            }
        }
    }

    // --- RECONCILIATION / CROWDSOURCING ENGINE ---
    // Fetch all user transactions of type 'Dividend' for this ticker
    $stmtTx = $pdo->prepare("SELECT date, amount, amount_cur, currency, price FROM broker_trans WHERE user_id = ? AND id = ? AND trans_type = 'Dividend'");
    $stmtTx->execute([$userId, $ticker]);
    $txs = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all cached dividends for this ticker to match in memory (avoiding complex dual-DB datediff queries)
    $stmtDivs = $pdo->prepare("SELECT ex_date, amount, currency, source FROM dividend_history WHERE ticker = ?");
    $stmtDivs->execute([$ticker]);
    $divs = $stmtDivs->fetchAll(PDO::FETCH_ASSOC);

    $verifiedCount = 0;
    $newImportCount = 0;

    foreach ($txs as $tx) {
        $txDate = $tx['date'];
        $txTimestamp = strtotime($txDate);
        
        // Calculate user gross payout per share
        $txAmount = (float)$tx['amount'];
        $txAmountCur = (float)$tx['amount_cur'];
        $txPrice = (float)$tx['price'];
        $txCurrency = strtoupper(trim($tx['currency']));

        $txPerShare = ($txAmount > 0) ? ($txAmountCur / $txAmount) : ($txPrice > 0 ? $txPrice : 0);
        if ($txPerShare <= 0) continue;

        // Search for a matching dividend in cached dividend_history
        $matched = false;
        foreach ($divs as &$div) {
            $exDate = $div['ex_date'];
            $exTimestamp = strtotime($exDate);
            $divCurrency = strtoupper(trim($div['currency']));

            // Payment date is usually 1 to 45 days after the ex-dividend date
            $diffDays = ($txTimestamp - $exTimestamp) / 86400;
            
            // Check if currency matches and the date difference is within reasonable range [0, 45] days
            if ($txCurrency === $divCurrency && $diffDays >= 0 && $diffDays <= 45) {
                // Check if per-share amount matches within 5% tolerance
                $divAmount = (float)$div['amount'];
                if ($divAmount > 0 && abs($txPerShare - $divAmount) / $divAmount <= 0.05) {
                    // Match found! Update database as verified
                    $stmtUpd = $pdo->prepare("UPDATE dividend_history SET source = 'user_verified' WHERE ticker = ? AND ex_date = ?");
                    $stmtUpd->execute([$ticker, $exDate]);
                    $div['source'] = 'user_verified';
                    $matched = true;
                    $verifiedCount++;
                    break;
                }
            }
        }

        // If no match found, this is a unique dividend that Yahoo is missing or has wrong data.
        // Insert it into dividend_history as 'user_import' (crowdsourcing).
        if (!$matched) {
            // Estimate ex-date as transaction date (payment date) minus 15 days, or just use transaction date
            $estExDate = date('Y-m-d', strtotime('-15 days', $txTimestamp));
            
            if ($driver === 'pgsql') {
                $sqlInsert = "INSERT INTO dividend_history (ticker, ex_date, amount, currency, source)
                              VALUES (?, ?, ?, ?, 'user_import')
                              ON CONFLICT (ticker, ex_date) DO NOTHING";
            } else {
                $sqlInsert = "INSERT INTO dividend_history (ticker, ex_date, amount, currency, source)
                              VALUES (?, ?, ?, ?, 'user_import')
                              ON DUPLICATE KEY UPDATE amount = VALUES(amount)";
            }
            $stmt = $pdo->prepare($sqlInsert);
            $stmt->execute([$ticker, $estExDate, $txPerShare, $txCurrency]);
            
            $newImportCount++;
            // Add to divs so we don't insert duplicate crowdsourced records for same transaction
            $divs[] = ['ex_date' => $estExDate, 'amount' => $txPerShare, 'currency' => $txCurrency, 'source' => 'user_import'];
        }
    }

    // --- RE-CALCULATE METRICS & UPDATE live_quotes ---
    // Fetch all current dividends again for accurate stats
    $stmtDivsAll = $pdo->prepare("SELECT ex_date, amount, currency, source FROM dividend_history WHERE ticker = ? ORDER BY ex_date DESC");
    $stmtDivsAll->execute([$ticker]);
    $allDivs = $stmtDivsAll->fetchAll(PDO::FETCH_ASSOC);

    $exDividendDate = null;
    $dividendRate = 0.0;
    $dividendYield = 0.0;
    $dividendFrequency = 'Irregular';
    $fiveYearAvgYield = 0.0;
    $currency = 'USD';

    if ($allDivs) {
        $currency = $allDivs[0]['currency'];
        $exDividendDate = $allDivs[0]['ex_date'];

        // TTM Dividend Rate (sum of last 365 days)
        $oneYearAgo = strtotime('-365 days');
        foreach ($allDivs as $d) {
            if (strtotime($d['ex_date']) >= $oneYearAgo) {
                $dividendRate += (float)$d['amount'];
            }
        }

        // Calculate frequency (count in last 2 years)
        $twoYearsAgo = strtotime('-2 years');
        $twoYearCount = 0;
        foreach ($allDivs as $d) {
            if (strtotime($d['ex_date']) >= $twoYearsAgo) {
                $twoYearCount++;
            }
        }
        
        $annualCount = $twoYearCount / 2;
        if ($annualCount >= 10) {
            $dividendFrequency = 'Monthly';
        } elseif ($annualCount >= 3) {
            $dividendFrequency = 'Quarterly';
        } elseif ($annualCount >= 1.5) {
            $dividendFrequency = 'Semi-Annual';
        } elseif ($annualCount >= 0.7) {
            $dividendFrequency = 'Annual';
        }

        // Fetch current price
        $stmtPrice = $pdo->prepare("SELECT current_price FROM live_quotes WHERE ticker = ?");
        $stmtPrice->execute([$ticker]);
        $currentPrice = (float)$stmtPrice->fetchColumn();

        if ($currentPrice > 0) {
            $dividendYield = ($dividendRate / $currentPrice) * 100; // in percent
        }

        // Calculate 5-year average yield
        $yearlyPayouts = array_fill(0, 5, 0.0);
        $currentYear = (int)date('Y');
        
        foreach ($allDivs as $d) {
            $year = (int)date('Y', strtotime($d['ex_date']));
            $index = $currentYear - $year;
            if ($index >= 0 && $index < 5) {
                $yearlyPayouts[$index] += (float)$d['amount'];
            }
        }

        $activeYears = 0;
        $totalPayoutSum = 0.0;
        foreach ($yearlyPayouts as $payout) {
            if ($payout > 0) {
                $activeYears++;
            }
            $totalPayoutSum += $payout;
        }

        $avgAnnualPayout = $activeYears > 0 ? ($totalPayoutSum / 5) : 0.0;
        if ($currentPrice > 0) {
            $fiveYearAvgYield = ($avgAnnualPayout / $currentPrice) * 100; // in percent
        }
    }

    // Update live_quotes with calculated metrics
    $sqlLQ = "UPDATE live_quotes SET 
              ex_dividend_date = ?,
              dividend_rate = ?,
              dividend_yield = ?,
              dividend_frequency = ?,
              five_year_avg_yield = ?
              WHERE ticker = ?";
    $stmtLQ = $pdo->prepare($sqlLQ);
    $stmtLQ->execute([$exDividendDate, $dividendRate, $dividendYield, $dividendFrequency, $fiveYearAvgYield, $ticker]);

    echo json_encode([
        'success' => true,
        'ticker' => $ticker,
        'cached_count' => count($allDivs),
        'verified_count' => $verifiedCount,
        'new_import_count' => $newImportCount,
        'metrics' => [
            'ex_dividend_date' => $exDividendDate,
            'dividend_rate' => $dividendRate,
            'dividend_yield' => $dividendYield,
            'dividend_frequency' => $dividendFrequency,
            'five_year_avg_yield' => $fiveYearAvgYield,
            'currency' => $currency
        ]
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
