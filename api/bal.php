<?php
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) {
    header("Location: ../index.html");
    exit;
}

/* ===== Resolve User ID (robust) ===== */
function resolveUserIdFromSession() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) {
            return [(int)$_SESSION[$k], $k];
        }
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) {
            foreach (['user_id','id','uid','userid'] as $k) {
                if (isset($u[$k]) && is_numeric($u[$k]) && (int)$u[$k] > 0) {
                    return [(int)$u[$k], 'user['.$k.']'];
                }
            }
        } elseif (is_object($u)) {
            foreach (['user_id','id','uid','userid'] as $k) {
                if (isset($u->$k) && is_numeric($u->$k) && (int)$u->$k > 0) {
                    return [(int)$u->$k, 'user->'.$k];
                }
            }
        }
    }
    return [null, null];
}
list($currentUserId,) = resolveUserIdFromSession();

$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Uživatel';
$currentPage = 'bal';
if (!$userName && isset($_SESSION['user']['name'])) {
    $userName = $_SESSION['user']['name'];
}

/* ===== DB připojení ===== */
$pdo = null;
try {
    $paths = [
        __DIR__ . '/../env.local.php',
        __DIR__ . '/env.local.php',
        __DIR__ . '/php/env.local.php',
        '../env.local.php',
        'php/env.local.php',
        '../php/env.local.php',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            require_once $p;
            break;
        }
    }
    if (defined('DB_HOST')) {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } else {
        throw new Exception('DB config nenalezen');
    }
} catch (Exception $e) {
    // případný log
}

/* ===== Google Finance Service - automatické načítání při zobrazení ===== */
$googleFinance = null;
$servicePath = __DIR__ . '/googlefinanceservice.php';
if (file_exists($servicePath)) {
    require_once $servicePath;
    try {
        // TTL = 0 znamená: použij dnešní data, jinak načti nová
        $googleFinance = new GoogleFinanceService($pdo, 0);
    } catch (Exception $e) {
        error_log('GoogleFinanceService init error: ' . $e->getMessage());
    }
}


/* ===== Konstanta pro cache živých cen ===== */
if (!defined('LIVE_QUOTE_CACHE_FILE')) {
    define('LIVE_QUOTE_CACHE_FILE', __DIR__ . '/cache/live_quotes.json');
}
if (!defined('LIVE_QUOTE_TTL')) {
    define('LIVE_QUOTE_TTL', 3600); // 1 hodina
}

/* ===== Helper funkce ===== */

function getLookupWithCounts($pdo, $userId, $col) {
    $allowed = ['id','currency','platform','product_type','trans_type'];
    if (!in_array($col, $allowed, true)) return [];
    $sql = "SELECT $col AS val, COUNT(*) AS c
            FROM broker_trans
            WHERE user_id = ?
              AND $col IS NOT NULL
              AND $col <> ''
            GROUP BY $col
            ORDER BY $col";
    $st = $pdo->prepare($sql);
    $st->execute([$userId]);
    return $st->fetchAll();
}

/**
 * Průměrná nákupní cena a zbývající množství pro aktuálně držené pozice.
 */
function calculateOpenPositionAveragePrice($pdo, $userId, $ticker, $asOfDate, $platform = '') {
    // Nákupy
    $sql = "SELECT date, amount, price, amount_czk, ex_rate, currency, fees
            FROM broker_trans
            WHERE user_id = ?
              AND id = ?
              AND trans_type = 'Buy'
              AND date <= ?
              AND (product_type = 'Stock' OR product_type = 'Crypto')";
    $params = [$userId, $ticker, $asOfDate];
    if ($platform !== '') {
        $sql .= " AND platform = ?";
        $params[] = $platform;
    }
    $sql .= " ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll();

    // Revenue (staking rewards) - increases quantity
    $sqlRevenue = "SELECT date, amount, price, amount_czk, ex_rate, currency
                   FROM broker_trans
                   WHERE user_id = ?
                     AND id = ?
                     AND trans_type = 'Revenue'
                     AND date <= ?
                     AND (product_type = 'Stock' OR product_type = 'Crypto')";
    $paramsRevenue = [$userId, $ticker, $asOfDate];
    if ($platform !== '') {
        $sqlRevenue .= " AND platform = ?";
        $paramsRevenue[] = $platform;
    }
    $sqlRevenue .= " ORDER BY date ASC";
    $stmtRevenue = $pdo->prepare($sqlRevenue);
    $stmtRevenue->execute($paramsRevenue);
    $revenues = $stmtRevenue->fetchAll();

    // Prodeje
    $sqlSells = "SELECT date, amount, price, fees
                 FROM broker_trans
                 WHERE user_id = ?
                   AND id = ?
                   AND trans_type = 'Sell'
                   AND date <= ?
                   AND (product_type = 'Stock' OR product_type = 'Crypto')";
    $paramsSells = [$userId, $ticker, $asOfDate];
    if ($platform !== '') {
        $sqlSells .= " AND platform = ?";
        $paramsSells[] = $platform;
    }
    $stmtSells = $pdo->prepare($sqlSells);
    $stmtSells->execute($paramsSells);
    $sells = $stmtSells->fetchAll();

    $totalBought       = 0.0;
    $totalCostCZK      = 0.0;
    $totalSold         = 0.0;
    $totalCostOriginal = 0.0;
    $weightedExRate    = 0.0;

    // Defined known crypto tickers for fee logic
    $cryptoTickers = ['BTC', 'ETH', 'ADA', 'DOT', 'SOL', 'MATIC', 'AVAX', 'LINK', 'UNI', 'ATOM', 'XRP', 'LTC', 'BCH', 'DOGE', 'SHIB'];
    $isCrypto = in_array(strtoupper($ticker), $cryptoTickers, true);

    foreach ($purchases as $p) {
        $amount = (float)$p['amount'];
        $totalBought  += $amount;
        $totalCostCZK += abs((float)$p['amount_czk']);

        $price          = (float)$p['price'];
        $costInOriginal = $amount * $price;
        $totalCostOriginal += $costInOriginal;
        $weightedExRate    += $costInOriginal * (float)$p['ex_rate'];
        
        // Fee logic for purchases (Revolut Crypto: Fees reduce amount received)
        if($isCrypto) {
             // We need to fetch fees column for purchases too
             // But $purchases query didn't select fees!
             // Assuming I will update correct query below.
             if(isset($p['fees'])) {
                 $fees = abs((float)$p['fees']);
                 if($fees > 0 && $price > 0) {
                     $feeQty = $fees / $price;
                     $totalBought -= $feeQty;
                 }
             }
        }
    }

    // Add revenue (staking rewards) to quantity
    foreach ($revenues as $r) {
        $amount = (float)$r['amount'];
        $totalBought += $amount;
        
        $revenueCostCZK = abs((float)$r['amount_czk']);
        if ($revenueCostCZK > 0) {
            $totalCostCZK += $revenueCostCZK;
            
            $price = (float)$r['price'];
            if ($price > 0) {
                $costInOriginal = $amount * $price;
                $totalCostOriginal += $costInOriginal;
                $weightedExRate += $costInOriginal * (float)$r['ex_rate'];
            }
        }
    }

    foreach ($sells as $s) {
        $soldAmt = (float)$s['amount'];
        $totalSold += $soldAmt;
        
        // Logic for Crypto Fees being deducted from balance (Revolut style)
        // If we sold X quantity, but paid Fee Y (value), that Fee likely came from additional sold quantity or reduced the payout.
        // User observation: Outgoing amount > Sum(Sell Amounts). Difference matches fees.
        // So we must ADD the fee-equivalent quantity to the "Sold" pile (as it is gone from portfolio).
        if ($isCrypto) {
            $fees = abs((float)$s['fees']);
            $price = (float)$s['price'];
            if ($fees > 0 && $price > 0) {
                // Calculate quantity burned by fees
                // Assuming fees and price are in same currency (e.g. CZK) or ratio holds valid.
                $feeQty = $fees / $price;
                $totalSold += $feeQty;
            }
        }
    }

    $remainingQty = $totalBought - $totalSold;

    if ($remainingQty <= 0 || $totalBought <= 0) {
        return [
            'avg_price_czk'       => 0,
            'avg_price_original'  => 0,
            'avg_ex_rate'         => 0,
            'total_qty'           => 0,
            'first_purchase_date' => null,
        ];
    }

    $avgPriceCZK      = $totalCostCZK / $totalBought;
    $avgPriceOriginal = $totalCostOriginal / $totalBought;
    $avgExRate        = $totalCostOriginal > 0 ? $weightedExRate / $totalCostOriginal : 0;
    $firstPurchaseDate = !empty($purchases) ? $purchases[0]['date'] : null;

    return [
        'avg_price_czk'       => $avgPriceCZK,
        'avg_price_original'  => $avgPriceOriginal,
        'avg_ex_rate'         => $avgExRate,
        'total_qty'           => $remainingQty,
        'first_purchase_date' => $firstPurchaseDate,
    ];
}

/**
 * Průměrná nákupní cena k okamžiku prodeje – pro výpočet realizovaného P&L.
 */
function calculateAveragePurchasePriceForSale($pdo, $userId, $ticker, $sellDate, $platform = null) {
    $sql = "SELECT date, amount, price, amount_czk, ex_rate, currency 
            FROM broker_trans 
            WHERE user_id = ? 
              AND id = ? 
              AND trans_type = 'Buy' 
              AND date <= ? 
              AND (product_type = 'Stock' OR product_type = 'Crypto')";
    $params = [$userId, $ticker, $sellDate];
    if ($platform) {
        $sql .= " AND platform = ?";
        $params[] = $platform;
    }
    $sql .= " ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll();

    $sqlSells = "SELECT date, amount 
                 FROM broker_trans 
                 WHERE user_id = ? 
                   AND id = ? 
                   AND trans_type = 'Sell' 
                   AND date < ? 
                   AND (product_type = 'Stock' OR product_type = 'Crypto')";
    $paramsSells = [$userId, $ticker, $sellDate];
    if ($platform) {
        $sqlSells .= " AND platform = ?";
        $paramsSells[] = $platform;
    }
    $stmtSells = $pdo->prepare($sqlSells);
    $stmtSells->execute($paramsSells);
    $previousSells = $stmtSells->fetchAll();

    $totalBought       = 0.0;
    $totalCostCZK      = 0.0;
    $totalSold         = 0.0;
    $totalCostOriginal = 0.0;
    $weightedExRate    = 0.0;

    foreach ($purchases as $p) {
        $amount = (float)$p['amount'];
        $totalBought  += $amount;
        $totalCostCZK += abs((float)$p['amount_czk']);

        $costInOriginal = (float)$p['amount'] * (float)$p['price'];
        $totalCostOriginal += $costInOriginal;
        $weightedExRate += $costInOriginal * (float)$p['ex_rate'];
    }

    foreach ($previousSells as $s) {
        $totalSold += (float)$s['amount'];
    }

    $remainingQty = $totalBought - $totalSold;

    if ($remainingQty <= 0 || $totalBought <= 0) {
        return [
            'avg_price_czk'      => 0,
            'avg_price_original' => 0,
            'avg_ex_rate'        => 0,
            'total_qty'          => 0,
            'first_purchase_date'=> null,
        ];
    }

    $avgPriceCZK      = $totalCostCZK / $totalBought;
    $avgPriceOriginal = $totalCostOriginal / $totalBought;
    $avgExRate        = $totalCostOriginal > 0 ? $weightedExRate / $totalCostOriginal : 0;
    $firstPurchaseDate = !empty($purchases) ? $purchases[0]['date'] : null;

    return [
        'avg_price_czk'      => $avgPriceCZK,
        'avg_price_original' => $avgPriceOriginal,
        'avg_ex_rate'        => $avgExRate,
        'total_qty'          => $remainingQty,
        'first_purchase_date'=> $firstPurchaseDate,
    ];
}

/**
 * Nejnovější FX kurz z tabulky broker_exrates (CZK za 1 jednotku měny).
 */
function getLatestFxRate($pdo, $currency, $asOfDate) {
    if (!$currency || $currency === 'CZK') {
        return 1.0;
    }
    if (!$asOfDate) {
        $asOfDate = date('Y-m-d');
    }

    $sql = "SELECT rate, amount
            FROM broker_exrates
            WHERE currency = ?
              AND date <= ?
            ORDER BY date DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$currency, $asOfDate]);
    $row = $stmt->fetch();

    if ($row) {
        $amount = (int)$row['amount'];
        if ($amount <= 0) $amount = 1;
        return (float)$row['rate'] / $amount;
    }
    return 1.0;
}

/**
 * Získá živé ceny - automaticky natáhne z Google Finance pokud nejsou dnešní
 */
function getLiveQuotesFromCache($pdo, $googleFinance, $ticker, $currency) {
    // Pokud máme Google Finance Service
    if ($googleFinance !== null) {
        try {
            // Zkusíme získat dnešní data z DB
            $today = date('Y-m-d');
            
            $sql = "SELECT current_price, change_amount, change_percent, 
                           currency as quote_currency, volume, market_cap, pe_ratio, 
                           company_name, last_fetched
                    FROM broker_live_quotes
                    WHERE id = ?
                      AND DATE(last_fetched) = ?
                      AND status = 'active'
                      AND current_price IS NOT NULL";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ticker, $today]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Pokud máme dnešní data, použijeme je
            if ($cached && $cached['current_price'] > 0) {
                return (float)$cached['current_price'];
            }
            
            // Nemáme dnešní data - natáhneme z Google Finance
            $freshData = $googleFinance->getQuote($ticker, true);
            
            if ($freshData !== null && isset($freshData['current_price']) && $freshData['current_price'] > 0) {
                return (float)$freshData['current_price'];
            }
            
        } catch (Exception $e) {
            error_log('getLiveQuotesFromCache error: ' . $e->getMessage());
        }
    }
    
    // Fallback na původní metodu
    return fetchLiveQuote($ticker, $currency);
}

/**
 * Živá cena z Google Finance (stocks) nebo CoinGecko (crypto) s jednoduchou file-cache a heuristikou.
 */
function fetchLiveQuote($symbol, $currency) {
    static $memCache = [];
    static $fileCacheLoaded = false;
    static $fileCache = [];

    // Detect crypto tickers and use CoinGecko API instead of Google Finance
    $cryptoTickers = [
        'BTC' => 'bitcoin',
        'ETH' => 'ethereum',
        'ADA' => 'cardano',
        'DOT' => 'polkadot',
        'SOL' => 'solana',
        'MATIC' => 'matic-network',
        'AVAX' => 'avalanche-2',
        'LINK' => 'chainlink',
        'UNI' => 'uniswap',
        'ATOM' => 'cosmos',
        'XRP' => 'ripple',
        'LTC' => 'litecoin',
        'BCH' => 'bitcoin-cash',
        'DOGE' => 'dogecoin',
        'SHIB' => 'shiba-inu'
    ];
    
    $symbolUpper = strtoupper($symbol);
    $isCrypto = isset($cryptoTickers[$symbolUpper]);

    if (isset($memCache[$symbol])) {
        return $memCache[$symbol];
    }

    $cacheFile = LIVE_QUOTE_CACHE_FILE;

    if (!$fileCacheLoaded) {
        if (is_readable($cacheFile)) {
            $json = file_get_contents($cacheFile);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $fileCache = $data;
            }
        }
        $fileCacheLoaded = true;
    }

    $now   = time();
    $stale = null;

    if (isset($fileCache[$symbol]['price'])) {
        $entry = $fileCache[$symbol];
        if (isset($entry['ts']) && ($now - (int)$entry['ts']) <= LIVE_QUOTE_TTL) {
            $memCache[$symbol] = (float)$entry['price'];
            return $memCache[$symbol];
        } else {
            $stale = (float)$entry['price'];
        }
    }

    $price = null;

    // For crypto, use CoinGecko API
    if ($isCrypto) {
        $coinGeckoId = $cryptoTickers[$symbolUpper];
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . urlencode($coinGeckoId) . '&vs_currencies=usd';
        
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 3,
                'header'  => "User-Agent: Mozilla/5.0 (PortfolioTracker)\r\nAccept: application/json\r\n",
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response !== false && $response !== '') {
            $data = json_decode($response, true);
            if (isset($data[$coinGeckoId]['usd'])) {
                $price = (float)$data[$coinGeckoId]['usd'];
            }
        }
    } else {
        // For stocks, use Google Finance (existing logic)
        $exchanges = [];
        switch ($currency) {
            case 'USD':
                $exchanges = ['NASDAQ','NYSE','NYSEARCA','NYSEAMERICAN'];
                break;
            case 'EUR':
                $exchanges = ['XETRA','ETR','FRA','AMS','VIE'];
                break;
            case 'GBP':
                $exchanges = ['LON'];
                break;
            case 'CAD':
                $exchanges = ['TSE','CVE'];
                break;
            case 'JPY':
                $exchanges = ['TYO'];
                break;
            case 'AUD':
                $exchanges = ['ASX'];
                break;
            case 'HKD':
                $exchanges = ['HKG'];
                break;
            default:
                $exchanges = [];
        }

        $candidates = [];
        foreach ($exchanges as $ex) {
            $candidates[] = $symbol . ':' . $ex;
        }
        $candidates[] = $symbol;

        foreach ($candidates as $code) {
            $url = 'https://www.google.com/finance/quote/' . urlencode($code) . '?hl=en';

            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => 2,
                    'header'  => "User-Agent: Mozilla/5.0 (PortfolioTracker)\r\nAccept: text/html\r\n",
                ]
            ]);

            $html = @file_get_contents($url, false, $context);
            if ($html === false || $html === '') {
                continue;
            }

            if (preg_match('~<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>(.*?)</div>~s', $html, $m)) {
                $text = strip_tags(htmlspecialchars_decode($m[1], ENT_QUOTES | ENT_HTML5));
                $text = str_replace(["\xc2\xa0", ' ', ','], '', $text);
                $text = preg_replace('/[^\d\.\-]/', '', $text);

                if ($text !== '' && is_numeric($text)) {
                    $price = (float)$text;
                    if ($price > 0) {
                        break;
                    }
                }
            }
        }
    }

    if ($price === null) {
        if ($stale !== null) {
            $memCache[$symbol] = $stale;
            return $stale;
        }
        $memCache[$symbol] = null;
        return null;
    }

    $memCache[$symbol] = $price;
    $fileCache[$symbol] = [
        'price' => $price,
        'ts'    => $now,
    ];

    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($cacheFile, json_encode($fileCache));

    return $price;
}

/**
 * Celkový realizovaný P&L v CZK (zisk - ztráta - poplatky) pro uzavřené obchody.
 */
function getRealizedNetProfitCZK($pdo, $userId, $symbolFilter = '', $currencyFilter = '', $platformFilter = '', $productFilter = '') {
    if (!$pdo || !$userId) return 0.0;

    $sql = "SELECT trans_id, date, id, platform, currency,
                   amount, price, ex_rate, amount_cur, amount_czk, fees
            FROM broker_trans
            WHERE user_id = ?";
    
    // Default filtering or specific product
    if ($productFilter !== '') {
        $sql .= " AND product_type = ?";
        $params = [$userId, $productFilter];
    } else {
        $sql .= " AND (product_type = 'Stock' OR product_type = 'Crypto')";
        $params = [$userId];
    }
              
    $sql .= " AND trans_type = 'Sell'";

    if ($symbolFilter !== '') {
        $sql .= " AND id = ?";
        $params[] = $symbolFilter;
    }
    if ($currencyFilter !== '') {
        $sql .= " AND currency = ?";
        $params[] = $currencyFilter;
    }
    if ($platformFilter !== '') {
        $sql .= " AND platform = ?";
        $params[] = $platformFilter;
    }

    $sql .= " ORDER BY date ASC, trans_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();

    $totalProfitCzk = 0.0;
    $totalLossCzk   = 0.0;
    $totalFees      = 0.0;

    foreach ($sales as $sale) {
        $sellQty = (float)$sale['amount'];
        if ($sellQty <= 0) continue;

        $avgData = calculateAveragePurchasePriceForSale(
            $pdo,
            $userId,
            $sale['id'],
            $sale['date'],
            $sale['platform']
        );

        $sellTotalCZK = abs((float)$sale['amount_czk']);
        if ($sellTotalCZK <= 0 || $sellQty <= 0) continue;

        $sellPriceCZK  = $sellTotalCZK / $sellQty;
        $avgBuyCZK     = (float)$avgData['avg_price_czk'];

        $profitCZK = ($sellPriceCZK - $avgBuyCZK) * $sellQty;
        $fees      = (float)$sale['fees'];

        if ($profitCZK > 0) {
            $totalProfitCzk += $profitCZK;
        } else {
            $totalLossCzk   += abs($profitCZK);
        }
        $totalFees += $fees;
    }

    $net = $totalProfitCzk - $totalLossCzk - $totalFees;
    return $net;
}

/* ===== Filtry ===== */

$symbol   = isset($_GET['symbol'])   ? trim($_GET['symbol'])   : '';
$currency = isset($_GET['currency']) ? trim($_GET['currency']) : '';
$platform = isset($_GET['platform']) ? trim($_GET['platform']) : '';
$product  = isset($_GET['product'])  ? trim($_GET['product'])  : ''; // Added Product Filter
$groupMode = isset($_GET['cons']) ? $_GET['cons'] : 'none';   // none, currency, platform, all
$grouped   = ($groupMode !== 'none');
$useLive   = isset($_GET['live']) ? (int)$_GET['live'] : 0;   // Default 0 = fast load, async fetch
$sortCol   = isset($_GET['sort']) ? $_GET['sort'] : 'unrealized_pct';
$sortDir   = isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC' ? 'ASC' : 'DESC';

// Whitelist sort columns to prevent SQL injection
$allowedSorts = [
    'id', 'platform', 'currency', 'qty', 'avg_price_orig', 'avg_price_czk', 
    'cost_total_czk', 'cost_total_orig', 'last_price_orig', 'last_price_czk', 
    'current_value_czk', 'unrealized_czk', 'unrealized_pct', 
    'unrealized_orig', 'unrealized_pct_orig', 'fx_pl_czk'
];
if (!in_array($sortCol, $allowedSorts)) {
    $sortCol = 'unrealized_pct';
}

/* ===== Výpočet dat ===== */

$positions    = [];
$rows_grouped = [];
$summary = [
    'positions_count'      => 0,
    'total_cost_czk'       => 0.0,
    'total_current_czk'    => 0.0,
    'total_unrealized_czk' => 0.0,
    'positive_positions'   => 0,
    'negative_positions'   => 0,
];

$ids        = [];
$currencies = [];
$platforms  = [];
$realizedNetProfitCZK = 0.0;

if ($pdo && $currentUserId) {
    $ids        = getLookupWithCounts($pdo, $currentUserId, 'id');
    $currencies = getLookupWithCounts($pdo, $currentUserId, 'currency');
    $platforms  = getLookupWithCounts($pdo, $currentUserId, 'platform');
    $products   = getLookupWithCounts($pdo, $currentUserId, 'product_type'); // Added Products Lookup

    // Create Temporary Table
    $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS portfolio_snapshot (
        id VARCHAR(50),
        currency VARCHAR(10),
        platform VARCHAR(50),
        qty DECIMAL(20,8),
        avg_price_czk DECIMAL(20,8),
        avg_price_orig DECIMAL(20,8),
        avg_ex_rate DECIMAL(20,8),
        cost_total_czk DECIMAL(20,2),
        cost_total_orig DECIMAL(20,2),
        last_price_orig DECIMAL(20,8),
        last_price_czk DECIMAL(20,8),
        current_value_czk DECIMAL(20,2),
        unrealized_czk DECIMAL(20,2),
        unrealized_pct DECIMAL(20,2),
        unrealized_orig DECIMAL(20,2),
        unrealized_pct_orig DECIMAL(20,2),
        fx_pl_czk DECIMAL(20,2),
        first_purchase_date DATE,
        price_is_fresh TINYINT DEFAULT 0
    )");
    $pdo->exec("TRUNCATE TABLE portfolio_snapshot");

    $asOfDate = date('Y-m-d');
    
    // Disable sync live price fetching to prevent timeouts (503 errors)
    // Client-side JS will fetch live prices asynchronously.
    // If user explicitly requests live=1 via URL, honor it (but warn it's slow)
    // $useLive is already set from $_GET['live'] above (default 0)
    // We REMOVE the override that forced it to true.

    // For ticker-only aggregation, ignore currency and platform splits
    $tickerOnlyMode = ($groupMode === 'ticker');
    
    
    if ($tickerOnlyMode) {
        $cols = "DISTINCT id";
    } else {
        $cols = "DISTINCT id, currency, platform";
    }

    $productSql = ($product !== '') ? "product_type = ?" : "(product_type = 'Stock' OR product_type = 'Crypto')";
    $params = [$currentUserId];
    
    $sql = "SELECT $cols
            FROM broker_trans
            WHERE user_id = ?
              AND $productSql
              AND (trans_type = 'Buy' OR trans_type = 'Sell' OR trans_type = 'Revenue')";
    
    if ($product !== '') {
        $params[] = $product;
    }

    if ($symbol !== '') {
        $sql .= " AND id = ?";
        $params[] = $symbol;
    }
    if ($currency !== '') {
        $sql .= " AND currency = ?";
        $params[] = $currency;
    }
    if ($platform !== '') {
        $sql .= " AND platform = ?";
        $params[] = $platform;
    }

    $sql .= " ORDER BY id, platform";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $instruments = $stmt->fetchAll();

    $insertStmt = $pdo->prepare("INSERT INTO portfolio_snapshot 
        (id, currency, platform, qty, avg_price_czk, avg_price_orig, avg_ex_rate, 
         cost_total_czk, cost_total_orig, last_price_orig, last_price_czk, 
         current_value_czk, unrealized_czk, unrealized_pct, unrealized_orig, 
         unrealized_pct_orig, fx_pl_czk, first_purchase_date, price_is_fresh)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($instruments as $inst) {
        $ticker = $inst['id'];
        
        if ($tickerOnlyMode) {
            // In ticker-only mode, aggregate ALL positions for this ticker (all currencies, all platforms)
            $cur  = 'MULTI'; // Placeholder to indicate multi-currency
            $plat = '';      // Empty platform means aggregate across all platforms
        } else {
            $cur  = $inst['currency'];
            $plat = $inst['platform'];
        }

        // Defined known crypto tickers
        $cryptoTickers = ['BTC', 'ETH', 'ADA', 'DOT', 'SOL', 'MATIC', 'AVAX', 'LINK', 'UNI', 'ATOM', 'XRP', 'LTC', 'BCH', 'DOGE', 'SHIB']; 
        $isCrypto = in_array(strtoupper($ticker), $cryptoTickers, true);

        // nákupní průměr + zbývající množství
        $avg = calculateOpenPositionAveragePrice($pdo, $currentUserId, $ticker, $asOfDate, $plat);
        if (!$avg || $avg['total_qty'] <= 0) {
            continue; // vše prodáno
        }

        $qty          = (float)$avg['total_qty'];
        $avgPriceCZK  = (float)$avg['avg_price_czk'];
        $avgPriceOrig = (float)$avg['avg_price_original'];
        $avgExRate    = (float)$avg['avg_ex_rate'];

        $costTotalCZK  = $qty * $avgPriceCZK;
        $costTotalOrig = $qty * $avgPriceOrig;

        // poslední cena z transakcí (pro kontrolu/rezervu)
        $sqlLast = "SELECT date, price, ex_rate
                    FROM broker_trans
                    WHERE user_id = ?
                      AND id = ?
                      AND platform = ?
                      AND (product_type = 'Stock' OR product_type = 'Crypto')
                      AND (trans_type = 'Buy' OR trans_type = 'Sell' OR trans_type = 'Revenue')
                      AND price IS NOT NULL
                    ORDER BY date DESC, trans_id DESC
                    LIMIT 1";
        $stmtLast = $pdo->prepare($sqlLast);
        $stmtLast->execute([$currentUserId, $ticker, $plat]);
        $last = $stmtLast->fetch();

        $lastPriceOrig = null;
        $lastExRate    = null;
        if ($last) {
            $lastPriceOrig = (float)$last['price'];
            $lastExRate    = (float)$last['ex_rate'];
        }

        // živá cena
        $livePriceOrig = null;
        $liveCurrency  = null;
        $priceIsFresh  = 0;
        
        // 1. Try fast cache (ALWAYS, to support instant load if data is fresh)
        $stmtCache = $pdo->prepare("SELECT current_price, currency FROM broker_live_quotes WHERE id = ? AND status='active' AND DATE(last_fetched) = CURRENT_DATE()");
        $stmtCache->execute([$ticker]);
        $cachedRow = $stmtCache->fetch(PDO::FETCH_ASSOC);
        
        if ($cachedRow) {
             $livePriceOrig = (float)$cachedRow['current_price'];
             $liveCurrency  = $cachedRow['currency'] ? strtoupper($cachedRow['currency']) : null;
             $priceIsFresh  = 1;
        } elseif ($useLive) {
            // 2. Only scrape if explicitly requested (slow)
            $livePriceOrig = getLiveQuotesFromCache($pdo, $googleFinance, $ticker, $cur);
            if ($livePriceOrig !== null) {
                // Zjistíme měnu živé ceny z DB
                $stmtLc = $pdo->prepare("SELECT currency FROM broker_live_quotes WHERE id = ?");
                $stmtLc->execute([$ticker]);
                $lc = $stmtLc->fetchColumn();
                $liveCurrency = $lc ? strtoupper($lc) : null;
                $priceIsFresh  = 1; // If we just fetched it, it is fresh
            }
        }

        $priceOrig = null;
        
        // Determine if logic used Live price or Last price
        $usedLive = false;

        if ($livePriceOrig !== null && $livePriceOrig > 0) {
            // heuristika: porovnáme s poslední známou cenou
            if ($lastPriceOrig !== null && $lastPriceOrig > 0) {
                
                $checkLive = $livePriceOrig;
                
                // Pokud je portfolio v CZK, ale živá cena v cizí měně (např. USD), musíme pro porovnání převést
                // (Protože $lastPriceOrig je v měně portfolia, tj. CZK pro T212)
                // Ahoj! Tady je ten trik: u T212 je lastPrice v transakcích (Price/share) často v ORIGINÁLNÍ měně (USD),
                // ale currency transakce (Total) je v CZK.
                // TAKŽE musíme vědět, v jaké měně je uložená $lastPriceOrig.
                // V parseru jsme to opravili tak, že amount_cur je CZK, ale price je USD.
                // Ale $cur (portfolio currency) se bere z transakcí.
                
                // Zjednodušení: Předpokládejme, že $livePriceOrig je ta správná "tržní" cena v měně burzy.
                // Pokud $cur != $liveCurrency, znamená to, že držíme v jiné měně (nebo jen účetně).
                
                // Pro rozhodování použijeme poměr, ale musíme být opatrní na měny.
                // Pokud je to T212 (cur=CZK) a ticker je Stock (live=USD), lastPriceOrig je nejspíš USD (z parseru).
                // Takže porovnáváme USD vs USD. To je OK.
                
                // Jediný problém by byl, kdyby lastPriceOrig byl v CZK.
                // Ale pro T212 jsme řekli, že price/share je v asset currency.
                
                $ratio = $checkLive / $lastPriceOrig;
                
                if ($ratio > 3.0 || $ratio < 0.33) {
                    // Podezřelá odchylka -> raději fallback
                    $priceOrig = $lastPriceOrig;
                    $usedLive  = false;
                } else {
                    $priceOrig = $livePriceOrig;
                    $usedLive  = true;
                }
            } else {
                // Nemáme historii -> bereme live
                $priceOrig = $livePriceOrig;
                $usedLive  = true;
            }
        } elseif ($lastPriceOrig !== null && $lastPriceOrig > 0) {
            $priceOrig = $lastPriceOrig;
        } else {
            $priceOrig = $avgPriceOrig;
        }

        // Logic pre Currency
        $priceCurrency = $cur; // Default
        if ($usedLive && $liveCurrency && $liveCurrency !== $cur) {
            $priceCurrency = $liveCurrency;
        } elseif (!$usedLive && $lastPriceOrig) {
             // Pokud používáme lastPriceOrig, musíme vědět, v jaké je měně.
             // Pro T212 (CZK účet, USD akcie) je lastPrice v USD.
             // Jak to poznáme? $cur je CZK. 
             // Musíme se podívat, jestli $liveCurrency (pokud existuje) se liší od $cur.
             // Pokud ano, předpokládejme, že i lastPrice byla v téže tržní měně.
             if ($liveCurrency && $liveCurrency !== $cur) {
                 $priceCurrency = $liveCurrency;
             } elseif ($isCrypto) { // Fallback pro crypto, kde možná nemáme liveCurrency načtenou
                 $priceCurrency = 'USD'; 
             }
        } 
        
        // Special case: If we force live fetching but fallback happened, we must be careful.
        // Simplified approach: correctly map price currency based on known source.
        
        $fxCurrent = 1.0;
        if ($priceCurrency !== 'CZK') {
            $fxCurrent = getLatestFxRate($pdo, $priceCurrency, $asOfDate);
            // Fallback for missing rates
             if ($fxCurrent <= 0) {
                if ($lastExRate && $lastExRate > 0 && $priceCurrency == $cur) {
                    $fxCurrent = $lastExRate;
                } else {
                    $fxCurrent = ($priceCurrency === 'USD') ? 24.0 : 1.0;
                }
            }
        }

        $lastPriceOrigDisplay = $priceOrig;
        $lastPriceCZK         = $lastPriceOrigDisplay * $fxCurrent;
        $currentValueCZK      = $qty * $lastPriceCZK;

        $unrealizedCZK = $currentValueCZK - $costTotalCZK;
        $unrealizedPct = $costTotalCZK > 0 ? ($unrealizedCZK / $costTotalCZK) * 100.0 : 0.0;

        // P&L breakdown
        $unrealizedOrig = ($lastPriceOrigDisplay - $avgPriceOrig) * $qty;
        $unrealizedPctOrig = $costTotalOrig > 0 ? ($unrealizedOrig / $costTotalOrig) * 100.0 : 0.0;
        // Price P&L in CZK (theoretical gain if FX hadn't changed, but valued at current FX)
        // Actually, standard attribution:
        // 1. Price P&L = (Price_End - Price_Start) * Qty * FX_End
        // 2. FX P&L = Total P&L - Price P&L
        $pricePlCzk = $unrealizedOrig * $fxCurrent;
        $fxPlCzk    = $unrealizedCZK - $pricePlCzk;

        $insertStmt->execute([
            $ticker, $cur, $plat, $qty, $avgPriceCZK, $avgPriceOrig, $avgExRate,
            $costTotalCZK, $costTotalOrig, $lastPriceOrigDisplay, $lastPriceCZK,
            $currentValueCZK, $unrealizedCZK, $unrealizedPct, $unrealizedOrig,
            $unrealizedPctOrig, $fxPlCzk, $avg['first_purchase_date'], $priceIsFresh
        ]);
    }

    // Fetch Summary from Temp Table
    $sumSql = "SELECT 
                COUNT(*) as positions_count,
                SUM(cost_total_czk) as total_cost_czk,
                SUM(current_value_czk) as total_current_czk,
                SUM(unrealized_czk) as total_unrealized_czk,
                SUM(CASE WHEN unrealized_czk >= 0 THEN 1 ELSE 0 END) as positive_positions,
                SUM(CASE WHEN unrealized_czk < 0 THEN 1 ELSE 0 END) as negative_positions
               FROM portfolio_snapshot";
    $sumStmt = $pdo->query($sumSql);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch Sorted Positions
    $fetchSql = "SELECT * FROM portfolio_snapshot ORDER BY $sortCol $sortDir";
    $fetchStmt = $pdo->query($fetchSql);
    $positions = $fetchStmt->fetchAll(PDO::FETCH_ASSOC);

    // agregace (měna / platforma / celé portfolio)
    if ($grouped && !empty($positions)) {
        // ... (aggregation logic remains similar but can be optimized later)
        // For now, we'll keep the PHP aggregation for simplicity as it's complex to do purely in SQL with dynamic grouping
        // But we need to re-implement it because $positions is now populated from DB
        
        $groups = [];
        foreach ($positions as $p) {
            if ($groupMode === 'currency') {
                $key   = $p['currency'];
                $label = $p['currency'];
            } elseif ($groupMode === 'platform') {
                $key   = $p['platform'];
                $label = $p['platform'];
            } else {
                $key   = 'ALL';
                $label = 'Celé portfolio';
            }

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label'           => $label,
                    'currency'        => $groupMode === 'currency' ? $p['currency'] : '',
                    'platform'        => $groupMode === 'platform' ? $p['platform'] : '',
                    'positions_count' => 0,
                    'qty_total'       => 0.0,
                    'cost_total_czk'  => 0.0,
                    'current_czk'     => 0.0,
                    'unrealized_czk'  => 0.0,
                ];
            }

            $g =& $groups[$key];
            $g['positions_count']++;
            $g['qty_total']      += $p['qty'];
            $g['cost_total_czk'] += $p['cost_total_czk'];
            $g['current_czk']    += $p['current_value_czk'];
            $g['unrealized_czk'] += $p['unrealized_czk'];
            unset($g);
        }

        foreach ($groups as &$g) {
            if ($g['cost_total_czk'] > 0) {
                $g['unrealized_pct'] = ($g['unrealized_czk'] / $g['cost_total_czk']) * 100.0;
            } else {
                $g['unrealized_pct'] = 0.0;
            }
        }
        unset($g);

        $rows_grouped = array_values($groups);
        
        // Sort grouped rows if needed (simple PHP sort for now)
        // Note: The user asked for sorting in the form, which usually implies the detailed view.
        // Grouped view sorting is less critical but good to have.
    }

    // Realizovaný P&L (v CZK)
    $realizedNetProfitCZK = getRealizedNetProfitCZK($pdo, $currentUserId, $symbol, $currency, $platform, $product);
}

$portfolioGainPct = $summary['total_cost_czk'] > 0
    ? ($summary['total_current_czk'] / $summary['total_cost_czk'] - 1.0) * 100.0
    : 0.0;

?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <title>Aktuální portfolio</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="css/broker-overrides.css">
  <style>
    .portfolio-stats{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
      gap:10px;
      margin-bottom:16px;
    }

    .stat-card{
      background:#fff;
      border-radius:6px;
      padding:10px;
      box-shadow:0 1px 2px rgba(0,0,0,.08);
      border:1px solid #e5e7eb;
    }
    .stat-label{color:#6b7280;font-size:.6rem;font-weight:600;margin-bottom:3px;text-transform:uppercase;letter-spacing:.05em;}
    .stat-value{font-size:1rem;font-weight:700;color:#0f172a;}
    .stat-value.positive{color:#059669;}
    .stat-value.negative{color:#ef4444;}
    .stat-subtitle{font-size:.65rem;color:#6b7280;margin-top:2px;line-height:1.3;}

    .table{width:100%;border-collapse:separate;border-spacing:0;}
    .table th,.table td{padding:8px 10px;font-size:.85rem;}
    .table thead th{background:#f8fafc;border-bottom:1px solid #e5e7eb;color:#64748b;font-weight:600;cursor:pointer;white-space:nowrap;}
    .table tbody tr{border-bottom:1px solid #eef2f7;}
    .table tbody tr:hover{background:#f9fbff;}
    .table-container{overflow-x:auto;}

    .th-sort{display:inline-flex;align-items:center;gap:4px;}
    .th-sort .dir{font-size:10px;opacity:.6;}

    .num{text-align:right;font-variant-numeric:tabular-nums;}

    .w-text{min-width:80px;}
    .w-qty{min-width:90px;}
    .w-price{min-width:110px;}
    .w-amczk{min-width:120px;}
    .w-percent{min-width:90px;}

    .gain-cell{font-weight:600;}
    .gain-cell.positive{color:#059669;}
    .gain-cell.negative{color:#ef4444;}

    .content-box.rates-card{
      border-radius:14px;
      border:1px solid #e5e7eb;
      box-shadow:0 4px 16px rgba(15,23,42,.04);
    }
    /* Inline filter styles removed - now using external CSS (broker-overrides.css) */
  </style>
  <link rel="stylesheet" href="css/broker-overrides.css">
  <link rel="stylesheet" href="css/filter-mobile.css">
</head>
<body>
<header class="header">
  <nav class="nav-container">
    <a href="broker.php" class="logo">Portfolio Tracker</a>
    <ul class="nav-menu">
      <li class="nav-item">
        <a href="portfolio.php" class="nav-link<?php echo $currentPage === 'portfolio' ? ' active' : ''; ?>">Transakce</a>
      </li>
      <li class="nav-item">
        <a href="bal.php" class="nav-link<?php echo $currentPage === 'bal' ? ' active' : ''; ?>">Aktuální portfolio</a>
      </li>
      <li class="nav-item">
        <a href="sal.php" class="nav-link<?php echo $currentPage === 'sal' ? ' active' : ''; ?>">Realizované P&amp;L</a>
      </li>
      <li class="nav-item">
        <a href="import.php" class="nav-link<?php echo $currentPage === 'import' ? ' active' : ''; ?>">Import</a>
      </li>
      <li class="nav-item">
        <a href="rates.php" class="nav-link<?php echo $currentPage === 'rates' ? ' active' : ''; ?>">Směnné kurzy</a>
      </li>
      <li class="nav-item">
        <a href="div.php" class="nav-link<?php echo $currentPage === 'div' ? ' active' : ''; ?>">Dividendy</a>
      </li>
      <li class="nav-item">
        <a href="market.php" class="nav-link<?php echo $currentPage === 'market' ? ' active' : ''; ?>">Přehled trhu</a>
      </li>
    </ul>
    <div class="user-section">
      <span class="user-name">Uživatel: <?php echo htmlspecialchars($userName); ?></span>
      <a href="../menu.html" class="btn btn-secondary">Menu</a>
      <a href="../../php/logout.php" class="btn btn-danger">Odhlásit se</a>
    </div>
  </nav>
</header>

<main class="main-content">
  <div class="page-header">
  </div>

  <?php if (!$pdo): ?>
    <div class="alert alert-danger">Nepodařilo se připojit k databázi.</div>
  <?php elseif (!$currentUserId): ?>
    <div class="alert alert-warning">Není rozpoznán přihlášený uživatel.</div>
  <?php else: ?>

  <?php if ($summary['positions_count'] > 0): ?>
    <div class="portfolio-stats">
      <div class="stat-card">
        <div class="stat-label">Aktuální hodnota portfolia</div>
        <div class="stat-value" id="summary-total-current">
          <?php echo number_format($summary['total_current_czk'], 2, ',', ' '); ?> Kč
        </div>
        <div class="stat-subtitle">Podle aktuálních cen a posledních FX kurzů.</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Nákupní cena (cost basis)</div>
        <div class="stat-value">
          <?php echo number_format($summary['total_cost_czk'], 2, ',', ' '); ?> Kč
        </div>
        <div class="stat-subtitle">Součet nákupních cen přepočtených do CZK.</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Nerealizovaný zisk / ztráta</div>
        <?php $gainCls = $summary['total_unrealized_czk'] >= 0 ? 'positive' : 'negative'; ?>
        <div class="stat-value <?php echo $gainCls; ?>" id="summary-total-unrealized">
          <?php echo number_format($summary['total_unrealized_czk'], 2, ',', ' '); ?> Kč
        </div>
        <div class="stat-subtitle" id="summary-total-pct">
          Zhodnocení otevřených pozic: <?php echo number_format($portfolioGainPct, 2, ',', ' '); ?> %
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Realizovaný zisk / ztráta</div>
        <?php $realCls = $realizedNetProfitCZK >= 0 ? 'positive' : 'negative'; ?>
        <div class="stat-value <?php echo $realCls; ?>">
          <?php echo number_format($realizedNetProfitCZK, 2, ',', ' '); ?> Kč
        </div>
        <div class="stat-subtitle">
          Souhrn všech uzavřených akciových obchodů (viz záložka Realizované P&amp;L).
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Počet držených titulů</div>
        <div class="stat-value">
          <?php echo (int)$summary['positions_count']; ?>
        </div>
        <div class="stat-subtitle">
          <?php echo (int)$summary['positive_positions']; ?> v plusu,
          <?php echo (int)$summary['negative_positions']; ?> ve ztrátě.
        </div>
      </div>
    </div>
  <?php endif; ?>

  <!-- Filtry -->
  <!-- Filtry -->
  <div class="content-box rates-card">
    <h3 class="collapsible-header">Filtrování <span class="toggle-icon">▼</span></h3>
    <div class="collapsible-content">
    <form method="get" id="filterForm">
      <div class="filter-grid">
        <div class="form-group">
          <label for="symbol">Ticker / ISIN</label>
          <select id="symbol" name="symbol" class="input">
            <option value="">Vše</option>
            <?php foreach ($ids as $row): $v = $row['val']; $c = $row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($symbol === $v) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($v).' - '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="currency">Měna</label>
          <select id="currency" name="currency" class="input">
            <option value="">Vše</option>
            <?php foreach ($currencies as $row): $v = $row['val']; $c = $row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($currency === $v) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($v).' - '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="platform">Platforma</label>
          <select id="platform" name="platform" class="input">
            <option value="">Vše</option>
            <?php foreach ($platforms as $row): $v = $row['val']; $c = $row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($platform === $v) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($v).' - '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="product">Produkt</label>
          <select id="product" name="product" class="input">
            <option value="">Vše (Akcie i Krypto)</option>
            <?php foreach ($products as $row): $v = $row['val']; $c = $row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($product === $v) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($v).' - '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="cons">Agregace</label>
          <select id="cons" name="cons" class="input">
            <option value="none"     <?php echo $groupMode === 'none'     ? 'selected' : ''; ?>>Detailně podle titulů</option>
            <option value="ticker"   <?php echo $groupMode === 'ticker'   ? 'selected' : ''; ?>>Podle tickeru (sloučit měny)</option>
            <option value="currency" <?php echo $groupMode === 'currency' ? 'selected' : ''; ?>>Podle měny</option>
            <option value="platform" <?php echo $groupMode === 'platform' ? 'selected' : ''; ?>>Podle platformy</option>
            <option value="all"      <?php echo $groupMode === 'all'      ? 'selected' : ''; ?>>Souhrnně celé portfolio</option>
          </select>
        </div>
        <div class="form-group">
          <label for="sort">Seřadit podle</label>
          <select id="sort" name="sort" class="input">
            <option value="unrealized_pct" <?php echo $sortCol === 'unrealized_pct' ? 'selected' : ''; ?>>P&L % (CZK)</option>
            <option value="unrealized_czk" <?php echo $sortCol === 'unrealized_czk' ? 'selected' : ''; ?>>P&L (CZK)</option>
            <option value="current_value_czk" <?php echo $sortCol === 'current_value_czk' ? 'selected' : ''; ?>>Hodnota (CZK)</option>
            <option value="id" <?php echo $sortCol === 'id' ? 'selected' : ''; ?>>Ticker</option>
            <option value="unrealized_pct_orig" <?php echo $sortCol === 'unrealized_pct_orig' ? 'selected' : ''; ?>>P&L % (Orig)</option>
          </select>
        </div>
        <div class="form-group">
          <label for="dir">Směr řazení</label>
          <select id="dir" name="dir" class="input">
            <option value="DESC" <?php echo $sortDir === 'DESC' ? 'selected' : ''; ?>>Sestupně (nejvyšší první)</option>
            <option value="ASC" <?php echo $sortDir === 'ASC' ? 'selected' : ''; ?>>Vzestupně (nejnižší první)</option>
          </select>
        </div>
        <div class="form-group">
          <label for="live">Aktuální ceny</label>
          <select id="live" name="live" class="input">
            <option value="1" <?php echo $useLive ? 'selected' : ''; ?>>Z internetu (pomalejší)</option>
            <option value="0" <?php echo !$useLive ? 'selected' : ''; ?>>Pouze z transakcí</option>
          </select>
        </div>
        <div class="form-actions" style="display:flex;gap:8px;justify-content:flex-end;grid-column:1/-1;">
          <button type="submit" class="btn btn-primary">Filtrovat</button>
          <a href="bal.php" class="btn btn-secondary">Reset</a>
        </div>
      </div>
    </form>
    </div>
  </div>

  <!-- Tabulka -->
  <div class="content-box rates-card">
    <h3>
      <?php if ($grouped): ?>
        Aktuální portfolio – agregace
        <?php if ($groupMode === 'currency'): ?>podle měny
        <?php elseif ($groupMode === 'platform'): ?>podle platformy
        <?php else: ?>celkem<?php endif; ?>
      <?php else: ?>
        Aktuální portfolio – přehled titulů
      <?php endif; ?>
      <small style="color:#64748b;font-weight:normal;">
        (<?php echo $grouped ? count($rows_grouped) : count($positions); ?> záznamů)
      </small>
    </h3>

    <?php if ((!$grouped && empty($positions)) || ($grouped && empty($rows_grouped))): ?>
      <div class="alert alert-info">Žádné aktuálně držené pozice pro zadané filtry.</div>
    <?php else: ?>
      <div class="table-container">
        <?php if ($grouped): ?>
          <table class="table" id="txTable">
            <thead>
              <tr>
                <th data-key="label" data-type="text" class="w-text">
                  <span class="th-sort">
                    <?php
                      if     ($groupMode === 'currency') echo 'Měna';
                      elseif ($groupMode === 'platform') echo 'Platforma';
                      else                              echo 'Skupina';
                    ?>
                    <span class="dir"></span>
                  </span>
                </th>
                <th data-key="positions_count" data-type="number" class="w-qty">
                  <span class="th-sort">Tituly <span class="dir"></span></span>
                </th>
                <th data-key="cost_total_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">Nákup CZK <span class="dir"></span></span>
                </th>
                <th data-key="current_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">Hodnota CZK <span class="dir"></span></span>
                </th>
                <th data-key="unrealized_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">P&amp;L CZK <span class="dir"></span></span>
                </th>
                <th data-key="unrealized_pct" data-type="number" class="w-percent">
                  <span class="th-sort">P&amp;L % <span class="dir"></span></span>
                </th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows_grouped as $g): ?>
              <?php $gainCls = $g['unrealized_czk'] >= 0 ? 'positive' : 'negative'; ?>
              <tr
                data-label="<?php echo htmlspecialchars($g['label']); ?>"
                data-positions_count="<?php echo htmlspecialchars($g['positions_count']); ?>"
                data-cost_total_czk="<?php echo htmlspecialchars($g['cost_total_czk']); ?>"
                data-current_czk="<?php echo htmlspecialchars($g['current_czk']); ?>"
                data-unrealized_czk="<?php echo htmlspecialchars($g['unrealized_czk']); ?>"
                data-unrealized_pct="<?php echo htmlspecialchars($g['unrealized_pct']); ?>"
              >
                <td><?php echo htmlspecialchars($g['label']); ?></td>
                <td class="num"><?php echo (int)$g['positions_count']; ?></td>
                <td class="num"><?php echo number_format($g['cost_total_czk'], 2, ',', ' '); ?></td>
                <td class="num"><?php echo number_format($g['current_czk'], 2, ',', ' '); ?></td>
                <td class="num gain-cell <?php echo $gainCls; ?>">
                  <?php echo number_format($g['unrealized_czk'], 2, ',', ' '); ?>
                </td>
                <td class="num gain-cell <?php echo $gainCls; ?>">
                  <?php echo number_format($g['unrealized_pct'], 2, ',', ' '); ?> %
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <table class="table" id="txTable">
            <thead>
              <tr>
                <th data-key="id" data-type="text" class="w-text">
                  <span class="th-sort">Ticker <span class="dir"></span></span>
                </th>
                <th data-key="platform" data-type="text" class="w-text">
                  <span class="th-sort">Platforma <span class="dir"></span></span>
                </th>
                <th data-key="currency" data-type="text" class="w-text">
                  <span class="th-sort">Měna <span class="dir"></span></span>
                </th>
                <th data-key="qty" data-type="number" class="w-qty">
                  <span class="th-sort">Množství <span class="dir"></span></span>
                </th>
                <th data-key="avg_price_orig" data-type="number" class="w-price">
                  <span class="th-sort">Prům. cena (m.) <span class="dir"></span></span>
                </th>
                <th data-key="avg_price_czk" data-type="number" class="w-price">
                  <span class="th-sort">Prům. cena (CZK) <span class="dir"></span></span>
                </th>
                <th data-key="cost_total_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">Nákup CZK <span class="dir"></span></span>
                </th>
                <th data-key="last_price_orig" data-type="number" class="w-price">
                  <span class="th-sort">Akt. cena (m.) <span class="dir"></span></span>
                </th>
                <th data-key="current_value_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">Hodnota CZK <span class="dir"></span></span>
                </th>
                <th data-key="unrealized_orig" data-type="number" class="w-price">
                  <span class="th-sort">P&amp;L (m.) <span class="dir"></span></span>
                </th>
                <th data-key="unrealized_pct_orig" data-type="number" class="w-percent">
                  <span class="th-sort">P&amp;L % (m.) <span class="dir"></span></span>
                </th>
                <th data-key="fx_pl_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">FX P&amp;L (CZK) <span class="dir"></span></span>
                </th>
                <th data-key="unrealized_czk" data-type="number" class="w-amczk">
                  <span class="th-sort">P&amp;L CZK <span class="dir"></span></span>
                </th>
                <th data-key="unrealized_pct" data-type="number" class="w-percent">
                  <span class="th-sort">P&amp;L % <span class="dir"></span></span>
                </th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($positions as $p): ?>
              <?php $gainCls = $p['unrealized_czk'] >= 0 ? 'positive' : 'negative'; ?>
              <tr
                data-id="<?php echo htmlspecialchars($p['id']); ?>"
                data-platform="<?php echo htmlspecialchars($p['platform']); ?>"
                data-currency="<?php echo htmlspecialchars($p['currency']); ?>"
                data-qty="<?php echo htmlspecialchars($p['qty']); ?>"
                data-avg_price_orig="<?php echo htmlspecialchars($p['avg_price_orig']); ?>"
                data-avg_price_czk="<?php echo htmlspecialchars($p['avg_price_czk']); ?>"
                data-cost_total_czk="<?php echo htmlspecialchars($p['cost_total_czk']); ?>"
                data-cost_total_orig="<?php echo htmlspecialchars($p['cost_total_orig']); ?>"
                data-last_price_orig="<?php echo htmlspecialchars($p['last_price_orig']); ?>"
                data-last_price_czk="<?php echo htmlspecialchars($p['last_price_czk']); ?>"
                data-current_value_czk="<?php echo htmlspecialchars($p['current_value_czk']); ?>"
                data-unrealized_orig="<?php echo htmlspecialchars($p['unrealized_orig']); ?>"
                data-is-fresh="<?php echo (int)$p['price_is_fresh']; ?>"
                data-unrealized_pct_orig="<?php echo htmlspecialchars($p['unrealized_pct_orig']); ?>"
                data-fx_pl_czk="<?php echo htmlspecialchars($p['fx_pl_czk']); ?>"
                data-unrealized_czk="<?php echo htmlspecialchars($p['unrealized_czk']); ?>"
                data-unrealized_pct="<?php echo htmlspecialchars($p['unrealized_pct']); ?>"
              >
                <td><?php echo htmlspecialchars($p['id']); ?></td>
                <td><?php echo htmlspecialchars($p['platform']); ?></td>
                <td><?php echo htmlspecialchars($p['currency']); ?></td>
                <td class="num"><?php echo number_format($p['qty'], 4, ',', ' '); ?></td>
                <td class="num"><?php echo number_format($p['avg_price_orig'], 4, ',', ' '); ?></td>
                <td class="num"><?php echo number_format($p['avg_price_czk'], 2, ',', ' '); ?></td>
                <td class="num"><?php echo number_format($p['cost_total_czk'], 2, ',', ' '); ?></td>
                <td class="num"><?php echo number_format($p['last_price_orig'], 4, ',', ' '); ?></td>
                <td class="w-amczk"><?php echo number_format($p['current_value_czk'], 0, ',', ' '); ?></td>
                
                <?php $plOrigCls = $p['unrealized_orig'] >= 0 ? 'positive' : 'negative'; ?>
                <td class="<?php echo $plOrigCls; ?> w-price"><?php echo number_format($p['unrealized_orig'], 2, ',', ' '); ?></td>
                
                <td class="<?php echo $plOrigCls; ?> w-percent">
                  <?php echo number_format($p['unrealized_pct_orig'], 2, ',', ' '); ?> %
                </td>
                
                <?php $fxPlCls = $p['fx_pl_czk'] >= 0 ? 'positive' : 'negative'; ?>
                <td class="<?php echo $fxPlCls; ?> w-amczk"><?php echo number_format($p['fx_pl_czk'], 0, ',', ' '); ?></td>

                <td class="<?php echo $gainCls; ?> w-amczk"><?php echo number_format($p['unrealized_czk'], 0, ',', ' '); ?></td>
                <td class="num gain-cell <?php echo $gainCls; ?>">
                  <?php echo number_format($p['unrealized_pct'], 2, ',', ' '); ?> %
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</main>

<script src="js/table-scroll.js"></script>
<script src="js/collapsible.js"></script>
<script src="js/manual-price-modal.js"></script>
<script src="js/live-prices-loader.js?v=<?php echo time(); ?>"></script>
<script>
(function(){
  var table = document.getElementById('txTable');
  if (!table) return;
  var thead = table.querySelector('thead');
  var tbody = table.querySelector('tbody');
  var currentKey = null;
  var currentDir = 1;

  function getVal(tr, key, type) {
    var attrName = 'data-' + key;
    var raw = tr.getAttribute(attrName);
    if (type === 'number') {
      var n = parseFloat(raw);
      return isNaN(n) ? 0 : n;
    }
    if (type === 'date') {
      var t = Date.parse(raw);
      return isNaN(t) ? 0 : t;
    }
    return (raw || '').toString().toLowerCase();
  }

  function setIndicator(th, dir) {
    var dirs = thead.querySelectorAll('.dir');
    for (var i=0;i<dirs.length;i++) dirs[i].textContent = '';
    var span = th.querySelector('.dir');
    if (span) span.textContent = dir === 1 ? '▲' : '▼';
  }

  thead.addEventListener('click', function(e){
    var th = e.target.closest('th');
    if (!th) return;
    var key  = th.getAttribute('data-key');
    var type = th.getAttribute('data-type') || 'text';
    if (!key) return;

    if (currentKey === key) currentDir *= -1;
    else { currentKey = key; currentDir = 1; }

    setIndicator(th, currentDir);

    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    rows.sort(function(a,b){
      var va = getVal(a,key,type);
      var vb = getVal(b,key,type);
      if (va < vb) return -1*currentDir;
      if (va > vb) return  1*currentDir;
      return 0;
    });
    rows.forEach(function(r){ tbody.appendChild(r); });
  });

  var form = document.getElementById('filterForm');
  if (form) {
    var selects = form.querySelectorAll('select');
    selects.forEach(function(sel){
      if (sel.id === 'cons') return; // agregaci necháme na tlačítko
      sel.addEventListener('change', function(){ form.submit(); });
    });
  }

  // ===== Async Live Price Loader =====
  // Changed from IIFE to regular function to allow explicit call in DOMContentLoaded


    // Function to check missing prices and show modal
    function runMissingPriceCheck() {
      console.log('[Manual Price] Checking for missing prices...');
      fetch('ajax-check-missing-prices.php')
        .then(function(response) { return response.json(); })
        .then(function(result) {
          if (result.success && result.missing_prices && result.missing_prices.length > 0) {
            console.log('[Manual Price] Missing prices for ' + result.count + ' tickers');
            if (typeof manualPriceModal !== 'undefined') {
              manualPriceModal.checkMissingPrices(result.missing_prices, function() {
                console.log('[Manual Price] All prices entered, reloading page...');
                location.reload();
              });
            } else {
              console.error('[Manual Price] manualPriceModal is not defined');
            }
          } else {
            console.log('[Manual Price] All tickers have current prices ✓');
          }
        })
        .catch(function(error) {
          console.error('[Manual Price] Error checking missing prices:', error);
        });
    }

    // Expose to window for external loaders
    window.runMissingPriceCheck = runMissingPriceCheck;

  })();

  // Mobile filter toggle functionality
  document.addEventListener('DOMContentLoaded', function() {
    const filterGrid = document.querySelector('.filter-grid');
    if (filterGrid && window.innerWidth <= 767) {
      // Create toggle button
      const toggleBtn = document.createElement('button');
      toggleBtn.className = 'filter-toggle-btn';
      toggleBtn.textContent = 'Zobrazit filtry';
      toggleBtn.type = 'button';
      
      // Insert before filter grid
      filterGrid.parentNode.insertBefore(toggleBtn, filterGrid);
      
      // Toggle functionality
      toggleBtn.addEventListener('click', function() {
        filterGrid.classList.toggle('show');
        toggleBtn.classList.toggle('active');
        toggleBtn.textContent = filterGrid.classList.contains('show') ? 'Skrýt filtry' : 'Zobrazit filtry';
      });
    }
  });
</script>
</body>
</html>