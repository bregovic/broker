<?php
// ajax-fetch-history.php
// v2.1 - Unified Price Updater (Yahoo Primary + Google Fallback)
// Respektuje: period (1d, 1y, max) nebo auto-smart.

ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

// Load Google Service
require_once __DIR__ . '/googlefinanceservice.php';

try {
    // 1. Config & DB
    $envPaths = [
        __DIR__ . '/env.local.php', __DIR__ . '/../env.local.php', 
        $_SERVER['DOCUMENT_ROOT'] . '/env.local.php', __DIR__ . '/env.php'
    ];
    foreach ($envPaths as $p) { if(file_exists($p)) { require_once $p; break; } }
    
    if (!defined('DB_HOST')) {
        if (file_exists(__DIR__ . '/db.php')) require_once __DIR__ . '/db.php';
        elseif (file_exists(__DIR__ . '/php/db.php')) require_once __DIR__ . '/php/db.php';
    }
    
    // DB Init
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Init Services
    $googleService = new GoogleFinanceService($pdo);

    // Helpers
    function mapTickerToYahoo($t) {
        $t = strtoupper(trim($t));
        // Common Cryptos for Yahoo
        $cryptos = ['BTC'=>'BTC-USD', 'ETH'=>'ETH-USD', 'SOL'=>'SOL-USD', 'XRP'=>'XRP-USD', 'ADA'=>'ADA-USD', 'LTC'=>'LTC-USD'];
        if (isset($cryptos[$t])) return $cryptos[$t];
        if ($t === 'BRK.B') return 'BRK-B';
        // Stocks Map
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
        
        // ETFs
        $etfMap = [
            'ZPRV'=>'ZPRV.DE', 'CNDX'=>'CNDX.L', 'CSPX'=>'CSPX.L', 'IWVL'=>'IWVL.L', 
            'VWRA'=>'VWRA.L', 'EQQQ'=>'EQQQ.DE', 'EUNL'=>'EUNL.DE', 'IS3N'=>'IS3N.DE', 
            'SXR8'=>'SXR8.DE', 'RBOT'=>'RBOT.L', 'RENW'=>'RENW.L'
        ];
        if (isset($etfMap[$t])) return $etfMap[$t];
        // CZ Stocks
        $czStocks = ['CEZ', 'KB', 'MONET', 'ERBAG', 'KOMB', 'PHILIP', 'COLT', 'KOFOL'];
        if (in_array($t, $czStocks)) return $t . '.PR';
        
        return $t;
    }

    function calcEMA($values, $period) {
        $count = count($values);
        if ($count < $period) return null;
        $sum = 0; 
        for ($i = 0; $i < $period; $i++) $sum += $values[$i];
        $ema = $sum / $period;
        $k = 2 / ($period + 1);
        for ($i = $period; $i < $count; $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }

    function resampleToWeekly($rows) {
        $weekly = [];
        foreach ($rows as $row) {
            $ts = strtotime($row['date']);
            $key = date('o-W', $ts); // ISO Year-Week
            $weekly[$key] = $row['price']; // Overwrite to keep last price of week
        }
        return array_values($weekly);
    }

    function fetchUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    // Main Processor
    function processTicker($pdo, $googleService, $ticker, $period) {
        $errorLog = [];
        $originalTicker = $ticker;

        // Resolve Alias
        try {
            $stmt = $pdo->prepare("SELECT alias_of FROM ticker_mapping WHERE ticker = ? AND alias_of != '' LIMIT 1");
            $stmt->execute([$ticker]);
            $alias = $stmt->fetchColumn();
            if ($alias) $ticker = $alias;
        } catch(Exception $e) {}

        $yahooTicker = mapTickerToYahoo($ticker);
        
        // Time Range Logic
        $end = time();
        $start = strtotime('-2 years'); // Default for EMA
        
        if ($period === 'max') {
            $start = strtotime('1980-01-01');
            if (strpos($yahooTicker, 'BTC') !== false) $start = strtotime('2014-09-15');
        } elseif ($period === '1y') {
            $start = strtotime('-1 year');
        } elseif ($period === '5y') {
            $start = strtotime('-5 years');
        } elseif ($period === 'current') {
             // Only fetch today/yesterday
             $start = strtotime('-5 days');
        } else {
            // Smart auto (default empty period)
            // Check last fetch
            try {
                $lastDate = $pdo->query("SELECT MAX(date) FROM tickers_history WHERE ticker = '$ticker'")->fetchColumn();
                if ($lastDate) {
                    $start = strtotime($lastDate) - (7 * 86400); // 1 week overlap
                } else {
                    $start = strtotime('-2 years');
                }
            } catch(Exception $e) {}
        }

        // --- ATTEMPT 1: YAHOO FINANCE ---
        $yahooSuccess = false;
        $usedSource = 'yahoo';
        
        // Fetch JSON
        $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($yahooTicker) . 
               "?period1=$start&period2=$end&interval=1d&events=history&includeAdjustedClose=true";
        
        $json = fetchUrl($url);
        
        $yahooData = null;
        if ($json) {
            $decoded = json_decode($json, true);
            $res = $decoded['chart']['result'][0] ?? null;
            // Validate: Must have timestamp array
            if ($res && !empty($res['timestamp'])) {
                $yahooSuccess = true;
                $yahooData = $res;
            }
        }
        
        // Retry logic for Dot/Hyphen (e.g. BRK.B)
        if (!$yahooSuccess && strpos($yahooTicker, '.') !== false) {
             // Heuristic: If it's a class share (single letter suffix)
             $suffix = substr(strrchr($yahooTicker, '.'), 1);
             if (strlen($suffix) === 1) { 
                 $alt = str_replace('.', '-', $yahooTicker);
                 $urlAlt = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($alt) . 
                           "?period1=$start&period2=$end&interval=1d&events=history&includeAdjustedClose=true";
                 $jsonAlt = fetchUrl($urlAlt);
                 if ($jsonAlt) {
                     $dAlt = json_decode($jsonAlt, true);
                     if (!empty($dAlt['chart']['result'][0]['timestamp'])) {
                         $yahooSuccess = true;
                         $yahooData = $dAlt['chart']['result'][0];
                         $yahooTicker = $alt;
                     }
                 }
             }
        }

        // --- ATTEMPT 2: GOOGLE FALLBACK ---
        if (!$yahooSuccess) {
            $usedSource = 'google_fallback';
            // GoogleService fetches current price and saves to DB.
            // It does NOT return history array, so EMA/Stats calculation will rely on old history + new single point.
            
            // Note: GoogleService writes to 'live_quotes' and 'tickers_history' (single day).
            $gRes = $googleService->getQuote($originalTicker, true); 
            
            if (!$gRes) {
                // Total Failure
                return ['status' => 'error', 'message' => 'Failed Yahoo & Google for ' . $originalTicker];
            }
            
            // If Google OK, we consider it a success for "live price" perspective.
            // We can try to calc stats if we have history in DB.
        } else {
            // --- YAHOO PROCESSING ---
            // 1. Save History
            $ts = $yahooData['timestamp'];
            $c = $yahooData['indicators']['quote'][0]['close'];
            
            // GBp fix?
            $factor = 1.0;
            // Investyx assumes major currency (GBP).
            // Heuristic: If price > 200 and previously stored price < 10, it's pence.
            $stmtP = $pdo->prepare("SELECT current_price FROM live_quotes WHERE id=?");
            $stmtP->execute([$originalTicker]);
            $lastP = (float)$stmtP->fetchColumn();
            
            $lastYahooVal = end($c);
            // Example: Yahoo sends 1200 (GBp). Last DB price was 12.0 (GBP). Ratio ~ 100.
            if ($lastP > 0 && $lastYahooVal > 0) {
                 $ratio = $lastYahooVal / $lastP;
                 if ($ratio > 50 && $ratio < 150) $factor = 0.01; 
            }

            $sqlH = "INSERT INTO tickers_history (ticker, date, price, source) VALUES (?, ?, ?, 'yahoo') ON DUPLICATE KEY UPDATE price=VALUES(price), source=VALUES(source)";
            $stmtH = $pdo->prepare($sqlH);
            
            $pdo->beginTransaction();
            foreach ($ts as $i => $t) {
                if (isset($c[$i]) && $c[$i] !== null) {
                    $d = date('Y-m-d', $t);
                    $val = $c[$i] * $factor;
                    $stmtH->execute([$ticker, $d, $val]);
                    if ($ticker !== $originalTicker) $stmtH->execute([$originalTicker, $d, $val]);
                }
            }
            $pdo->commit();
            
            // 2. Fetch Fundamentals (v7 quote) to update live_quotes table
            $qUrl = "https://query1.finance.yahoo.com/v7/finance/quote?symbols=" . urlencode($yahooTicker);
            $qJson = fetchUrl($qUrl);
            if ($qJson) {
                $qData = json_decode($qJson, true);
                $qRes = $qData['quoteResponse']['result'][0] ?? [];
                
                if ($qRes) {
                    $lp = $qRes['regularMarketPrice'] ?? end($c);
                    $chg = $qRes['regularMarketChange'] ?? 0;
                    $chgPct = $qRes['regularMarketChangePercent'] ?? 0;
                    
                    // Extract Exchange from Chart Meta
                    $exchangeName = $yahooData['meta']['fullExchangeName'] ?? $yahooData['meta']['exchangeName'] ?? '';

                    // Extract Company Name
                    $cn = $qRes['longName'] ?? $qRes['shortName'] ?? '';

                    // Update live_quotes
                    $sqlLQ = "UPDATE live_quotes SET 
                              current_price = :p, 
                              change_amount = :ca,
                              change_percent = :cp,
                              last_fetched = NOW(),
                              source = 'yahoo',
                              exchange = :ex,
                              company_name = COALESCE(NULLIF(:cn, ''), company_name)
                              WHERE id = :id";
                    
                    $pdo->prepare($sqlLQ)->execute([
                        ':p' => $lp * $factor, 
                        ':ca' => $chg * $factor,
                        ':cp' => $chgPct,
                        ':ex' => $exchangeName,
                        ':cn' => $cn,
                        ':id' => $originalTicker
                    ]);
                    
                    // Update Extra Fields
                     $updExtras = [];
                     $extraParams = [':id' => $originalTicker];
                     
                     if (isset($qRes['marketCap'])) {
                         $updExtras[] = "market_cap = :mc";
                         $extraParams[':mc'] = $qRes['marketCap'] * $factor;
                     }
                     if (isset($qRes['trailingPE'])) {
                         $updExtras[] = "pe_ratio = :pe";
                         $extraParams[':pe'] = $qRes['trailingPE'];
                     }
                     if (isset($qRes['dividendYield'])) {
                         $updExtras[] = "dividend_yield = :dy";
                         $extraParams[':dy'] = $qRes['dividendYield'];
                     }
                     
                     if (!empty($updExtras)) {
                         $sqlEx = "UPDATE live_quotes SET " . implode(', ', $updExtras) . " WHERE id = :id";
                         $pdo->prepare($sqlEx)->execute($extraParams);
                     }
                }
            }
        }

        // --- STATS CALC (EMA, ATH) ---
        // Fetch full history now (sorted)
        // Fetch full history now (sorted)
        $stmtAll = $pdo->prepare("SELECT date, price FROM tickers_history WHERE ticker=? ORDER BY date ASC");
        $stmtAll->execute([$originalTicker]); // Use original
        $rows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
        
        // Extract plain prices for high/low/resilience (Daily)
        $allPrices = array_column($rows, 'price');
        
        if ($rows) {
            $ath = max($allPrices);
            $atl = min($allPrices);
            
            // EMA 212 on Weekly Data
            $weeklyPrices = resampleToWeekly($rows);
            $ema = calcEMA($weeklyPrices, 212);
            
            // Resilience (Count of recoveries from >30% drops)
            // Logic: Cycle based. 
            // 1. Establish Peak.
            // 2. If drops > 30% from Peak -> Crash.
            // 3. If recovers to > 85% of Peak -> Recovery (+1), and Peak resets (to capture next local cycle).
            
            $resilience = 0;
            $peak = 0;
            $inCrash = false;
            
            foreach ($allPrices as $p) {
                if ($p <= 0) continue;
                
                if ($p > $peak) {
                    // New High
                    if ($inCrash) {
                        // Recovered to new High (Automatic recovery)
                        $resilience++;
                        $inCrash = false;
                    }
                    $peak = $p;
                } else {
                    // Check Recovery condition
                    if ($inCrash && $p >= $peak * 0.85) {
                        $resilience++;
                        $inCrash = false;
                        // Reset Peak to current level to start tracking local cycle (e.g. for IBM 2020 dip inside long stagnation)
                        $peak = $p;
                    }
                    
                    // Check Drop
                    $dd = ($peak - $p) / ($peak ?: 1);
                    if ($dd > 0.30 && !$inCrash) {
                        $inCrash = true;
                    }
                }
            }

            $sqlUpd = "UPDATE live_quotes SET all_time_high=?, all_time_low=?, ema_212=?, resilience_score=? WHERE id=?";
            $pdo->prepare($sqlUpd)->execute([$ath, $atl, $ema, $resilience, $originalTicker]);
        }
        
        return ['status' => 'ok', 'source' => $usedSource];
    }
    
    // 5. Input Handler
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);
    
    $action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? '');
    
    // List Mode
    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id FROM live_quotes WHERE status='active' ORDER BY id");
        echo json_encode(['success'=>true, 'tickers'=>$stmt->fetchAll(PDO::FETCH_COLUMN)]);
        exit;
    }
    
    // Batch/Single Mode
    $ticker = $_GET['ticker'] ?? $_POST['ticker'] ?? ($input['ticker'] ?? '');
    $period = $_GET['period'] ?? $_POST['period'] ?? ($input['period'] ?? 'smart');
    
    if ($ticker === 'ALL') {
         // Not supported here properly (timeout risk). Use Loop in frontend.
         echo json_encode(['success'=>false, 'message'=>'Use batch loop in frontend']);
         exit;
    }
    
    if ($ticker) {
        $res = processTicker($pdo, $googleService, $ticker, $period);
        echo json_encode(array_merge(['success'=> true], $res));
    } else {
        echo json_encode(['success'=>false, 'message'=>'No ticker']);
    }

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'message'=>$e->getMessage()]);
}
?>
