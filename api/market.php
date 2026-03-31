<?php
// market.php – Přehled trhu z broker_live_quotes (živá data z Google Finance)
// Zobrazuje poslední dostupné ceny, změny, P/E ratio, market cap atd.

session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) { header("Location: ../index.html"); exit; }

// ===== AJAX Handler for Google Finance Import =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        // This is a JSON request - handle import
        header('Content-Type: application/json; charset=utf-8');
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action']) || $input['action'] !== 'import_google_finance') {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        $ticker = strtoupper(trim($input['ticker'] ?? ''));
        if (empty($ticker)) {
            echo json_encode(['success' => false, 'message' => 'Ticker je povinný']);
            exit;
        }
        
        // Database connection for AJAX
        $paths = [__DIR__.'/../env.local.php', __DIR__.'/env.local.php', __DIR__.'/php/env.local.php'];
        foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }
        
        if (!defined('DB_HOST')) {
            echo json_encode(['success' => false, 'message' => 'Chyba konfigurace databáze']);
            exit;
        }
        
        try {
            $pdoAjax = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            
            // Simple direct fetch from Google Finance
            $success = false;
            $resultData = [];
            
            // Try different exchange combinations for US stocks
            $exchanges = ['NYSE', 'NASDAQ', 'NYSEARCA', 'NYSEAMERICAN', ''];
            
            // Special handling for known tickers
            $knownExchanges = [
                'BA' => 'NYSE',      // Boeing
                'AAPL' => 'NASDAQ',  // Apple
                'MSFT' => 'NASDAQ',  // Microsoft
                'GOOGL' => 'NASDAQ', // Google
                'AMZN' => 'NASDAQ',  // Amazon
                'TSLA' => 'NASDAQ',  // Tesla
                'JPM' => 'NYSE',     // JP Morgan
                'V' => 'NYSE',       // Visa
                'WMT' => 'NYSE',     // Walmart
                'DIS' => 'NYSE',     // Disney
                'NVDA' => 'NASDAQ',  // Nvidia
                'META' => 'NASDAQ',  // Meta
                'BRK.B' => 'NYSE',   // Berkshire
                'JNJ' => 'NYSE',     // Johnson & Johnson
                'PG' => 'NYSE',      // Procter & Gamble
            ];
            
            // If we know the exchange, try it first
            if (isset($knownExchanges[$ticker])) {
                array_unshift($exchanges, $knownExchanges[$ticker]);
                $exchanges = array_unique($exchanges);
            }
            
            $lastError = '';
            foreach ($exchanges as $ex) {
                $symbol = $ex ? $ticker . ':' . $ex : $ticker;
                $url = 'https://www.google.com/finance/quote/' . urlencode($symbol);
                
                $opts = [
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 10,
                        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n" .
                                  "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                                  "Accept-Language: en-US,en;q=0.5\r\n"
                    ]
                ];
                
                $html = @file_get_contents($url, false, stream_context_create($opts));
                
                if ($html) {
                    // Try multiple patterns for price
                    $patterns = [
                        '/<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>([^<]+)<\/div>/i',
                        '/<div[^>]+class="[^"]*YMlKec[^"]*"[^>]*>([^<]+)<\/div>/i',
                        '/<div[^>]+data-last-price="([^"]+)"/i',
                        '/data-last-price="([^"]+)"/i'
                    ];
                    
                    $price = 0;
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $html, $m)) {
                            $priceText = isset($m[1]) ? $m[1] : '';
                            $priceText = str_replace(['$', ',', ' ', "\xc2\xa0"], '', $priceText);
                            $price = (float)$priceText;
                            if ($price > 0) break;
                        }
                    }
                    
                    if ($price > 0) {
                        // Get company name if available
                        $company = $ticker;
                        if (preg_match('/<div[^>]+class="[^"]*zzDege[^"]*"[^>]*>([^<]+)<\/div>/i', $html, $cm)) {
                            $company = trim($cm[1]);
                        }
                        
                        // Get change percentage if available
                        $changePercent = 0;
                        if (preg_match('/\(([+\-]?\d+\.?\d*)%\)/', $html, $chm)) {
                            $changePercent = (float)$chm[1];
                        }
                        
                        // Save to database
                        $sql = "INSERT INTO broker_live_quotes (id, source, current_price, change_percent, company_name, exchange, last_fetched, status)
                                VALUES (:id, 'google_finance', :price, :change, :company, :exchange, NOW(), 'active')
                                ON DUPLICATE KEY UPDATE
                                current_price = VALUES(current_price),
                                change_percent = VALUES(change_percent),
                                company_name = VALUES(company_name),
                                exchange = VALUES(exchange),
                                last_fetched = NOW()";
                        
                        $stmt = $pdoAjax->prepare($sql);
                        $stmt->execute([
                            ':id' => $ticker,
                            ':price' => $price,
                            ':change' => $changePercent,
                            ':company' => $company,
                            ':exchange' => $ex ?: 'UNKNOWN'
                        ]);
                        
                        $resultData = [
                            'price' => $price,
                            'change' => $changePercent,
                            'company' => $company,
                            'exchange' => $ex
                        ];
                        $success = true;
                        break;
                    } else {
                        $lastError = "Cena nebyla nalezena na {$ex}";
                    }
                } else {
                    $lastError = "Nepodařilo se načíst stránku pro {$symbol}";
                }
            }
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Data importována', 'data' => $resultData]);
            } else {
                // Try to give more helpful error message
                $suggestions = '';
                if (strlen($ticker) < 2) {
                    $suggestions = ' Ticker musí mít alespoň 2 znaky.';
                } elseif (preg_match('/[^A-Z0-9\.]/', $ticker)) {
                    $suggestions = ' Ticker obsahuje neplatné znaky.';
                } else {
                    $suggestions = ' Zkuste např. AAPL, MSFT, GOOGL, AMZN, nebo BA (Boeing).';
                }
                echo json_encode(['success' => false, 'message' => 'Nepodařilo se získat data pro ' . $ticker . '.' . $suggestions]);
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Chyba: ' . $e->getMessage()]);
        }
        exit; // Important - stop execution after AJAX response
    }
}

// ===== Normal page processing continues here =====

/* ===== Resolve User ID ===== */
if (!function_exists('market_resolveUserIdFromSession')) {
  function market_resolveUserIdFromSession(): array {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
      if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return [(int)$_SESSION[$k], $k];
    }
    if (isset($_SESSION['user'])) {
      $u = $_SESSION['user'];
      if (is_array($u)) {
        foreach (['user_id','id','uid','userid'] as $k) {
          if (isset($u[$k]) && is_numeric($u[$k]) && (int)$u[$k] > 0) return [(int)$u[$k], 'user['.$k.']'];
        }
      } elseif (is_object($u)) {
        foreach (['user_id','id','uid','userid'] as $k) {
          if (isset($u->$k) && is_numeric($u->$k) && (int)$u->$k > 0) return [(int)$u->$k, 'user->'.$k];
        }
      }
    }
    return [null, null];
  }
}
list($currentUserId,) = market_resolveUserIdFromSession();
$userName = $_SESSION['name'] ?? ($_SESSION['user']['name'] ?? 'Uživatel');
$currentPage = 'market';

/* ===== DB připojení ===== */
$bootstrapCandidates = [
  'db.php','config/db.php','includes/db.php','inc/db.php',
  'config.php','includes/config.php','inc/config.php',
];
foreach ($bootstrapCandidates as $inc) {
  $p = __DIR__ . DIRECTORY_SEPARATOR . $inc;
  if (file_exists($p)) { require_once $p; }
}

$pdo    = isset($pdo)    ? $pdo    : (isset($db) && $db instanceof PDO ? $db : null);
$mysqli = isset($mysqli) ? $mysqli : (isset($conn) && $conn instanceof mysqli ? $conn : (isset($db) && $db instanceof mysqli ? $db : null));

/* ENV fallback */
if (!($pdo instanceof PDO) && !($mysqli instanceof mysqli)) {
  try {
    $paths=[__DIR__.'/../env.local.php',__DIR__.'/env.local.php',__DIR__.'/php/env.local.php','../env.local.php','php/env.local.php','../php/env.local.php'];
    foreach($paths as $p){ if(file_exists($p)){ require_once $p; break; } }
    if (defined('DB_HOST')) {
      $pdo=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      ]);
    }
  } catch(Throwable $e) { /* tichý fallback */ }
}

$hasDb  = ($pdo instanceof PDO) || ($mysqli instanceof mysqli);

/* ===== UI parametry ===== */
  $q = $_GET['q'] ?? '';
  $source = $_GET['source'] ?? '';
  $currency = $_GET['currency'] ?? '';
  $exchange = $_GET['exchange'] ?? '';
  
  // LOGIKA VÝCHOZÍHO ZOBRAZENÍ:
  // 1. Čistý příchod na stránku (bez parametrů) -> Zobrazit JEN MOJE (watched=1)
  // 2. Vyhledávání ($q) -> Zobrazit VŠE (watched=0), aby šlo najít nové
  // 3. Jinak respektovat checkbox
  
  if (empty($_GET)) {
      // Default state
      $watchedOnly = true;
  } elseif ($q !== '') {
      // Při hledání automaticky prohledáváme celý trh, pokud uživatel explicitně neřekl jinak
      // (Pokud by chtěl hledat jen ve svých, musel by zaškrtnout checkbox znovu)
      $watchedOnly = isset($_GET['watched']) ? (bool)$_GET['watched'] : false;
  } else {
      // Jinak bereme hodnotu z URL (checkboxu)
      $watchedOnly = !empty($_GET['watched']);
  }
  
  // Paging
  $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
  $perPage = 50;
  $offset = ($page - 1) * $perPage;

/* ===== Query helper ===== */
if (!function_exists('market_qAll')) {
  function market_qAll(string $sql, array $params = []) {
    global $pdo, $mysqli;
    if ($pdo instanceof PDO) {
      $st = $pdo->prepare($sql);
      foreach ($params as $k=>$v) {
        $type = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $st->bindValue(is_int($k)?$k+1:$k, $v, $type);
      }
      $st->execute();
      return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($mysqli instanceof mysqli) {
      if ($params) {
        foreach ($params as $k=>$v) {
          $safe = $mysqli->real_escape_string((string)$v);
          $sql = str_replace($k, "'$safe'", $sql);
        }
      }
      $res = $mysqli->query($sql);
      if (!$res) return [];
      $rows = [];
      while ($row = $res->fetch_assoc()) $rows[] = $row;
      return $rows;
    }
    return [];
  }
}

/* ===== Data z broker_live_quotes ===== */
$rows = []; $sources = $currs = $exchanges = []; $totalItems = 0; $totalPages = 1; $stats = [];

if ($hasDb) {
  // Auto-migration for history features
  try {
      $pdo->query("SELECT track_history FROM broker_live_quotes LIMIT 1");
  } catch (Exception $e) {
      // Column missing, add it
      $pdo->exec("ALTER TABLE broker_live_quotes ADD COLUMN track_history TINYINT(1) DEFAULT 0");
      // Create history table
      $pdo->exec("CREATE TABLE IF NOT EXISTS broker_price_history (
          id INT AUTO_INCREMENT PRIMARY KEY,
          ticker VARCHAR(20) NOT NULL,
          date DATE NOT NULL,
          price DECIMAL(12, 4),
          currency VARCHAR(10),
          source VARCHAR(20) DEFAULT 'yahoo',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY unique_price (ticker, date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

      // Auto-migration for watchlist (Simple & Robust)
      $pdo->exec("CREATE TABLE IF NOT EXISTS broker_watch (
          user_id INT NOT NULL,
          ticker VARCHAR(20) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (user_id, ticker)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }

  $where = []; $params = [];
  
  // Pouze aktivní záznamy
  $where[] = "status = 'active'";
  
  if ($q !== '')        { $where[] = "(t.id LIKE :q OR t.company_name LIKE :q)"; $params[':q'] = "%$q%"; }
  if ($source !== '')   { $where[] = "source = :src";   $params[':src'] = $source; }
  if ($currency !== '') { $where[] = "currency = :ccy"; $params[':ccy'] = $currency; }
  if ($exchange !== '') { $where[] = "exchange = :exch"; $params[':exch'] = $exchange; }
  // Note: track_history filter replaced by watched logic but kept for backward compat if needed
  
  // Přidáme param pro user_id (použijeme v Joinech)
  $params[':uid'] = $currentUserId;

  // Logika pro "Moje sledované" (Watchlist + Owned)
  if ($watchedOnly) {
      $where[] = "(w.ticker IS NOT NULL OR p.cnt > 0)";
  }
  
  // ŘAZENÍ (Smart Sort)
  $sort = $_GET['sort'] ?? '';
  $orderBy = "
    CASE WHEN (w.ticker IS NOT NULL OR p.cnt > 0) THEN 1 ELSE 0 END DESC,
    t.track_history DESC, 
    t.id ASC
  "; // Default
  
  if ($sort === 'resilience') {
      $orderBy = "t.resilience_score DESC, t.id ASC";
  } elseif ($sort === 'dip') {
      // Největší propad pod EMA 212 (Current / EMA nejmenší). Null/0 EMA na konec.
      $orderBy = "CASE WHEN t.ema_212 > 0 THEN 0 ELSE 1 END ASC, (t.current_price / t.ema_212) ASC, t.id ASC";
  } elseif ($sort === 'ath') {
      // Blízko ATH (Current / ATH největší). Null/0 ATH na konec.
      $orderBy = "CASE WHEN t.all_time_high > 0 THEN 0 ELSE 1 END ASC, (t.current_price / t.all_time_high) DESC, t.id ASC";
  } elseif ($sort === 'sell_owned') {
      // Vlastněné, blízko ATH (prodat na maximu)
      $where[] = "p.cnt > 0";
      $orderBy = "CASE WHEN t.all_time_high > 0 THEN 0 ELSE 1 END ASC, (t.current_price / t.all_time_high) DESC, t.id ASC";
  }
  
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // Definice JOINů (použijeme v datech i v countu, aby seděly parametry :uid)
  $joinSql = "
    LEFT JOIN broker_watch w ON t.id = w.ticker AND w.user_id = :uid
    LEFT JOIN (SELECT id, COUNT(*) as cnt FROM broker_trans WHERE user_id = :uid GROUP BY id) p ON t.id = p.id
  ";

  // Hlavní query - data
  $sql = "
  SELECT
    t.*,
    TIMESTAMPDIFF(MINUTE, t.last_fetched, NOW()) AS age_minutes,
    (SELECT price FROM broker_price_history h WHERE h.ticker = t.id AND h.date < CURDATE() ORDER BY h.date DESC LIMIT 1) as last_close_price,
    CASE WHEN w.ticker IS NOT NULL THEN 1 ELSE 0 END as is_watched,
    CASE WHEN p.cnt > 0 THEN 1 ELSE 0 END as is_owned
  FROM broker_live_quotes t
  " . $joinSql . "
  " . $whereSql . "
  ORDER BY " . $orderBy . "
  LIMIT :lim OFFSET :off
  ";
  
  $params2 = $params;
  $params2[':lim'] = (int)$perPage;
  $params2[':off'] = (int)$offset;

  $rows = market_qAll($sql, $params2);
  
  // Dropdown options (bez parametrů)
  $sources = array_column(market_qAll("SELECT DISTINCT source FROM broker_live_quotes WHERE status='active' ORDER BY source"), 'source');
  $currs   = array_column(market_qAll("SELECT DISTINCT currency FROM broker_live_quotes WHERE status='active' ORDER BY currency"), 'currency');
  $exchanges = array_column(market_qAll("SELECT DISTINCT exchange FROM broker_live_quotes WHERE status='active' AND exchange IS NOT NULL ORDER BY exchange"), 'exchange');
  
  // Celkový počet (musí obsahovat JOINy, aby seděl parametr :uid a filtry t.id)
  $countSql = "SELECT COUNT(*) AS c FROM broker_live_quotes t " . $joinSql . " " . $whereSql;
  $totalItems = (int) (market_qAll($countSql, $params)[0]['c'] ?? 0);
  $totalPages = max(1, (int)ceil($totalItems / $perPage));
  
  // Statistiky
  $stats = market_qAll("
    SELECT 
      COUNT(*) as total_tickers,
      SUM(CASE WHEN change_percent > 0 THEN 1 ELSE 0 END) as positive,
      SUM(CASE WHEN change_percent < 0 THEN 1 ELSE 0 END) as negative,
      AVG(change_percent) as avg_change,
      MAX(last_fetched) as last_update
    FROM broker_live_quotes 
    WHERE status = 'active'
  ")[0] ?? [];
}

/* helpers */
if (!function_exists('market_fmtNum')) {
  function market_fmtNum($v, $dec = 2) {
    if ($v === null || $v === '') return '—';
    if (!is_numeric($v)) return htmlspecialchars((string)$v);
    $v = (float)$v;
    if ($v !== 0.0 && abs($v) < 1) $dec = max($dec, 4);
    return number_format($v, $dec, ',', ' ');
  }
}

if (!function_exists('market_fmtMarketCap')) {
  function market_fmtMarketCap($val) {
    if ($val === null || $val === '' || $val == 0) return '—';
    $v = (float)$val;
    if ($v >= 1000000000000) return number_format($v/1000000000000, 2, ',', ' ') . ' T';
    if ($v >= 1000000000) return number_format($v/1000000000, 2, ',', ' ') . ' B';
    if ($v >= 1000000) return number_format($v/1000000, 2, ',', ' ') . ' M';
    if ($v >= 1000) return number_format($v/1000, 2, ',', ' ') . ' K';
    return number_format($v, 0, ',', ' ');
  }
}

if (!function_exists('market_fmtVolume')) {
  function market_fmtVolume($val) {
    if ($val === null || $val === '' || $val == 0) return '—';
    $v = (float)$val;
    if ($v >= 1000000000) return number_format($v/1000000000, 2, ',', ' ') . ' B';
    if ($v >= 1000000) return number_format($v/1000000, 2, ',', ' ') . ' M';
    if ($v >= 1000) return number_format($v/1000, 2, ',', ' ') . ' K';
    return number_format($v, 0, ',', ' ');
  }
}

/* Calculate summary statistics */
$totalPositive = 0;
$totalNegative = 0;
$totalZero = 0;
$sumPercentChange = 0;

foreach ($rows as $row) {
  $change = (float)($row['change_percent'] ?? 0);
  $sumPercentChange += $change;
  if ($change > 0) $totalPositive++;
  elseif ($change < 0) $totalNegative++;
  else $totalZero++;
}

$avgChange = count($rows) > 0 ? $sumPercentChange / count($rows) : 0;
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Přehled trhu - Broker</title>
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/broker-overrides.css">
  <link rel="stylesheet" href="css/table-filter.css">
  <link rel="stylesheet" href="css/d365-theme.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="js/TableFilter.js"></script>
  <style>
    /* Additional styles for market page */
    .watch-star { cursor: pointer; color: #cbd5e1; font-size: 1.2em; margin-right: 6px; transition: all 0.2s; }
    .watch-star:hover { transform: scale(1.2); color: #f59e0b; }
    .watch-star.active { color: #f59e0b; }
    
    .btn-icon { background: none; border: none; cursor: pointer; font-size: 1.3em; padding: 4px; transition: opacity 0.2s; border-radius:4px; }
    .btn-icon:hover { opacity: 0.8; background:#f1f5f9; }
    
    /* Import button styling */
    .btn-import-data {
      background: linear-gradient(135deg, #059669, #16a34a) !important;
      color: white !important;
      font-weight: 600;
      padding: 10px 20px !important;
      border: none;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
    }
    
    .btn-import-data:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }
    
    /* Table styling matching sal.php */
    .table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      background: white;
      margin-top: 20px;
    }
    
    .table thead th {
      background: #f8fafc;
      padding: 12px 8px;
      text-align: left;
      font-weight: 600;
      color: #475569;
      border-bottom: 2px solid #e2e8f0;
      cursor: pointer;
      user-select: none;
      white-space: nowrap;
    }
    
    .table thead th:hover {
      background: #f1f5f9;
    }
    
    .table thead th .th-sort {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    
    .table thead th .dir {
      margin-left: 5px;
      color: #3b82f6;
      font-size: 12px;
    }
    
    .table tbody tr {
      border-bottom: 1px solid #e2e8f0;
    }
    
    .table tbody tr:hover {
      background: #f8fafc;
    }
    
    .table tbody td {
      padding: 10px 8px;
      color: #334155;
    }
    
    .ticker-cell {
      font-weight: 600;
      color: #1e293b;
    }
    
    .company-name {
      font-size: 12px;
      color: #64748b;
      margin-top: 2px;
    }
    
    .positive { color: #059669; }
    .negative { color: #ef4444; }
    
    .age-badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
      background: #e2e8f0;
      color: #64748b;
    }
    
    .age-badge.fresh {
      background: #dcfce7;
      color: #166534;
    }
    
    .age-badge.stale {
      background: #fef2f2;
      color: #991b1b;
    }
    
    .badge-exchange {
      display: inline-block;
      padding: 2px 6px;
      border-radius: 4px;
      font-size: 10px;
      font-weight: 600;
      background: #dbeafe;
      color: #1e40af;
      text-transform: uppercase;
    }
    
    /* Filter grid - matching sal.php */
    .filter-grid {
      display: grid;
      gap: 16px 20px;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      align-items: end;
      margin-bottom: 20px;
    }
    
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    
    .filter-group label {
      font-size: 13px;
      color: #475569;
      font-weight: 500;
    }
    
    .filter-group .input,
    .filter-group select {
      width: 100%;
      height: 44px;
      padding: 8px 12px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 14px;
      transition: all 0.2s;
    }
    
    .filter-group .input:focus,
    .filter-group select:focus {
      outline: none;
      border-color: #3b82f6;
      box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .filter-buttons {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    
    .results-count {
      padding: 6px 12px;
      background: #f1f5f9;
      border-radius: 6px;
      font-size: 13px;
      color: #475569;
      font-weight: 500;
    }
    
    /* Vylepšený design pro Collapsible Header */
    .filter-bar {
      margin-bottom: 20px;
    }
    
    .collapsible-header {
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 20px; /* Užší řádek */
      background: white;
      border: 1px solid #e2e8f0;
      border-radius: 12px; /* Větší radius */
      font-size: 14px;
      font-weight: 600;
      color: #475569;
      transition: all 0.2s ease;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    
    .collapsible-header:hover {
      background: #f8fafc;
      border-color: #cbd5e1;
      transform: translateY(-1px);
    }
    
    .collapsible-header.active {
      border-radius: 12px 12px 0 0;
      border-bottom: 1px solid #f1f5f9;
      background: #f8fafc;
    }
    
    .collapsible-content {
      background: white;
      border: 1px solid #e2e8f0;
      border-top: none;
      border-radius: 0 0 12px 12px;
      padding: 25px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      display: none; /* Defaultně zavřeno */
    }
    
    /* Modernizace tabulky */
    .table-container {
      background: white;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.05); /* Jemnější stín */
      overflow: hidden;
    }
    
    .table thead th {
      background: #f1f5f9;
      color: #64748b;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      padding: 15px 10px; /* Více vzduchu */
      border-bottom: 1px solid #e2e8f0;
    }
    
    .table tbody td {
      padding: 14px 10px; /* Více vzduchu */
      border-bottom: 1px solid #f1f5f9;
      font-size: 14px;
    }
    
    .table tbody tr:last-child td {
      border-bottom: none;
    }
    
    /* Tlačítka v tabulce */
    .btn-sm {
        padding: 4px 10px;
        font-size: 12px;
        border-radius: 6px;
    }
    
    /* Summary box */
    .summary-box {
      background: white;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      border: 1px solid #e2e8f0;
      margin-bottom: 30px;
    }
    
    .summary-title {
      font-size: 18px;
      font-weight: 600;
      color: #1e293b;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    
    .summary-icon {
      width: 32px;
      height: 32px;
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 16px;
    }
    
    /* Pagination */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 5px;
      margin-top: 30px;
      padding: 20px;
    }
    
    .pagination a,
    .pagination span {
      padding: 8px 12px;
      border-radius: 6px;
      text-decoration: none;
      color: #475569;
      font-weight: 500;
      transition: all 0.2s;
    }
    
    .pagination a:hover {
      background: #f1f5f9;
      color: #3b82f6;
    }
    
    .pagination span.active {
      background: #3b82f6;
      color: white;
    }
    
    .pagination span.muted {
      color: #cbd5e1;
    }
    /* New Smart Dashboard Styles */
    .smart-dashboard {
      background: white;
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); /* Jemný stín */
      margin-bottom: 25px;
      border: 1px solid #e2e8f0;
    }

    /* 1. Recommendation Chips */
    .reco-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      margin-bottom: 20px;
      padding-bottom: 20px;
      border-bottom: 1px solid #f1f5f9;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      border-radius: 99px; /* Pill shape */
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
      border: 1px solid transparent;
    }
    
    .chip i { font-size: 14px; }

    /* Styles for specific chips */
    .chip-resilience { background: #f0f9ff; color: #0284c7; border-color: #e0f2fe; }
    .chip-resilience:hover, .chip-resilience.active { background: #0284c7; color: white; border-color: #0284c7; }

    .chip-dip { background: #ecfdf5; color: #059669; border-color: #d1fae5; }
    .chip-dip:hover, .chip-dip.active { background: #059669; color: white; border-color: #059669; }

    .chip-ath { background: #fefce8; color: #ca8a04; border-color: #fef9c3; }
    .chip-ath:hover, .chip-ath.active { background: #ca8a04; color: white; border-color: #ca8a04; }

    .chip-sell { background: #fef2f2; color: #dc2626; border-color: #fee2e2; }
    .chip-sell:hover, .chip-sell.active { background: #dc2626; color: white; border-color: #dc2626; }
    
    .chip-reset { background: #f1f5f9; color: #64748b; }
    .chip-reset:hover { background: #e2e8f0; color: #475569; }

    /* 2. Toolbar Row */
    .toolbar-row {
      display: flex;
      gap: 15px;
      align-items: center;
      flex-wrap: wrap;
    }

    .toolbar-search {
      flex: 2;
      min-width: 200px;
      position: relative;
    }
    
    .toolbar-input {
      width: 100%;
      height: 42px;
      padding: 0 15px 0 40px; /* Space for icon */
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      font-size: 14px;
      background: #f8fafc;
      transition: all 0.2s;
    }
    .toolbar-input:focus { background: white; border-color: #3b82f6; outline: none; }

    .search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
    }

    .toolbar-add {
       flex: 2;
       display: flex;
       gap: 10px;
    }
    
    .toolbar-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      margin-left: auto;
    }
    
    /* Toggle Switch Style */
    .toggle-switch-label {
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13px;
      font-weight: 600;
      color: #64748b;
      user-select: none;
      padding: 6px 12px;
      border-radius: 8px;
      transition: background 0.2s;
    }
    .toggle-switch-label:hover { background: #f1f5f9; }
    .toggle-switch-label input { display: none; }
    .toggle-switch-label.checked { color: #f59e0b; background: #fffbeb; }
    
    /* Responsive */
    @media (max-width: 768px) {
      .toolbar-row { flex-direction: column; align-items: stretch; }
      .toolbar-actions { margin-left: 0; justify-content: space-between; }
    }
    
    /* Keep previous styles */
    .btn-icon { background: none; border: none; cursor: pointer; font-size: 1.3em; padding: 4px; transition: opacity 0.2s; border-radius:4px; }
    .btn-icon:hover { opacity: 0.8; background:#f1f5f9; }
    .watch-star { cursor: pointer; color: #cbd5e1; font-size: 1.2em; margin-right: 6px; transition: all 0.2s; }
    .watch-star:hover { transform: scale(1.2); color: #f59e0b; }
    .watch-star.active { color: #f59e0b; }
    .badge-exchange { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; background: #dbeafe; color: #1e40af; text-transform: uppercase; }
    .age-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 500; background: #e2e8f0; color: #64748b; }
    .age-badge.fresh { background: #dcfce7; color: #166534; }
    .age-badge.stale { background: #fef2f2; color: #991b1b; }
    .table-container { background: white; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
    .table { width: 100%; border-collapse: separate; border-spacing: 0; background: white; }
    .table thead th { background: #f1f5f9; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; padding: 15px 10px; border-bottom: 1px solid #e2e8f0; cursor: pointer; user-select: none; white-space: nowrap; }
    .table tbody td { padding: 14px 10px; border-bottom: 1px solid #f1f5f9; font-size: 14px; color: #334155; }
    .ticker-cell { font-weight: 600; color: #1e293b; }
    
    /* Modal Styles */
    .modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); z-index: 1000;
        display: flex; align-items: center; justify-content: center;
        backdrop-filter: blur(2px);
    }
    .modal-content {
        background: white; border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        width: 90%; max-width: 600px;
        display: flex; flex-direction: column; overflow: hidden;
        animation: modalFadeIn 0.2s ease-out;
    }
    @keyframes modalFadeIn { from {opacity:0; transform:scale(0.95);} to {opacity:1; transform:scale(1);} }
    
    .modal-header {
        padding: 15px 20px; border-bottom: 1px solid #e2e8f0;
        display: flex; justify-content: space-between; align-items: center;
        background: white;
    }
    .modal-header h3 { margin: 0; font-size: 18px; color: #1e293b; }
    .modal-close {
        background: none; border: none; font-size: 24px; cursor: pointer; color: #64748b;
    }
    .modal-close:hover { color: #ef4444; }
  </style>
</head>
<body>

<header class="header">
  <nav class="nav-container">
    <a href="broker.php" class="logo">Portfolio Tracker</a>
    <ul class="nav-menu">
      <li class="nav-item"><a href="portfolio.php" class="nav-link">Transakce</a></li>
      <li class="nav-item"><a href="bal.php" class="nav-link">Aktuální portfolio</a></li>
      <li class="nav-item"><a href="sal.php" class="nav-link">Realizované P&L</a></li>
      <li class="nav-item"><a href="import.php" class="nav-link">Import</a></li>
      <li class="nav-item"><a href="rates.php" class="nav-link">Směnné kurzy</a></li>
      <li class="nav-item"><a href="div.php" class="nav-link">Dividendy</a></li>
      <li class="nav-item"><a href="market.php" class="nav-link active">Přehled trhu</a></li>
    </ul>
    <!-- User menu -->
    <div style="display: flex; gap: 10px; align-items: center;">
      <span style="color: #cbd5e1; font-size: 14px;">Uživatel: <strong style="color: white;"><?php echo htmlspecialchars($userName); ?></strong></span>
      <a href="/index_menu.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">Menu</a>
      <a href="/php/logout.php" class="btn btn-danger" style="padding: 6px 12px; font-size: 13px;">Odhlásit se</a>
    </div>
  </nav>
</header>
  
<div class="container main-content">

  <!-- Smart Dashboard (Everything Visible) -->
  <div class="smart-dashboard">
      <form method="get" id="filterForm">
        
        <!-- 1. Recommendation Chips -->
        <div class="reco-chips">
        <!-- D365 HEADER STRUCTURE -->
        <div class="d365-header-container">
            
            <!-- Phase 2: Action Pane (Command Bar) -->
            <div class="d365-action-pane">
                <button type="button" class="d365-command-btn" onclick="document.getElementById('addTickerContainer').style.display = document.getElementById('addTickerContainer').style.display==='none'?'flex':'none'">
                    <i class="fas fa-plus"></i> Nový
                </button>
                
                <button type="button" class="d365-command-btn" onclick="openWatchManager()">
                    <i class="fas fa-list-check"></i> Správce
                </button>
                
                <div style="width:1px; height:20px; background:#edebe9; margin:0 8px;"></div>
                
                <button type="button" class="d365-command-btn" onclick="location.reload()">
                    <i class="fas fa-sync"></i> Obnovit
                </button>
                
                <button type="button" class="d365-command-btn" onclick="updateLivePrices(this)">
                    <i class="fas fa-bolt"></i> Ceny
                </button>
                
                <button type="button" class="d365-command-btn" onclick="updateAllHistory(this)">
                    <i class="fas fa-chart-line"></i> Data
                </button>
                
                <div style="flex:1"></div>
                
                <!-- NEW TICKER INPUT (Hidden by default) -->
                <div id="addTickerContainer" style="display:none; align-items:center; gap:5px; margin-right: 16px;">
                     <input type="text" id="newTickerInput" class="form-control" placeholder="AAPL, BTC..." style="height:28px; font-size:13px; border-radius:0; border:1px solid #8a8886;">
                     <button type="button" class="btn btn-primary btn-sm" onclick="addNewTicker()" style="height:28px; line-height:1; border-radius:0;">Přidat</button>
                </div>
            </div>

            <!-- Phase 3: Title Row (Standard View + Filter) -->
            <div class="d365-title-row">
                <!-- VIEW SWITCHER (Server-side Toggle) -->
                <div class="d365-view-title" onclick="location.href='?watched=<?php echo $watchedOnly ? 0 : 1; ?>'" title="Přepnout zobrazení">
                    <?php echo $watchedOnly ? 'Moje sledované' : 'Standard view'; ?> <i class="fas fa-chevron-down"></i>
                </div>
                
                <!-- QUICK FILTER (Client-side) -->
                <div class="d365-quick-filter">
                    <input type="text" id="quickFilter" placeholder="Filter">
                    <i class="fas fa-filter"></i>
                </div>

                <!-- INFO / RECOMMENDATIONS -->
                <div class="d365-info-bar">
                    <span>Doporučení:</span>
                    <a href="?sort=resilience" class="d365-info-item accent-shield" title="Nejvyšší odolnost">
                        <i class="fas fa-shield-alt"></i> Odolnost
                    </a>
                    <a href="?sort=dip" class="d365-info-item accent-grow" title="Doporučený nákup">
                        <i class="fas fa-arrow-trend-up"></i> Nákup (Dip)
                    </a>
                    <a href="?sort=ath" class="d365-info-item accent-fire" title="Blízko ATH">
                        <i class="fas fa-rocket"></i> Blízko ATH
                    </a>
                    <?php if($sort): ?>
                        <a href="market.php" class="d365-info-item" style="color:#d13438;">
                            <i class="fas fa-times"></i> Zrušit
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Hidden legacy form for compatibility if needed -->
        <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
        
        <!-- OLD TOOLBAR REMOVED -->
      </form>
  </div>
  
  <!-- Import Dialog -->
  <div id="importDialog" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 12px; width: 90%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
      <h3 style="margin-top: 0; margin-bottom: 20px; color: #1e293b;">📊 Import dat z Google Finance</h3>
      
      <div style="margin-bottom: 20px;">
        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Ticker / ISIN:</label>
        <input type="text" id="importTicker" class="input" placeholder="např. AAPL, GOOGL, US0378331005" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;">
      </div>
      
      <div style="margin-bottom: 20px;">
        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Typ importu:</label>
        <select id="importType" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" onchange="toggleDateInputs()">
          <option value="today">Dnešní cena</option>
          <option value="date">Konkrétní datum</option>
          <option value="range">Rozsah dat</option>
        </select>
      </div>
      
      <div id="singleDateInput" style="display: none; margin-bottom: 20px;">
        <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Datum:</label>
        <input type="date" id="importDate" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" value="<?php echo date('Y-m-d'); ?>">
      </div>
      
      <div id="dateRangeInputs" style="display: none;">
        <div style="margin-bottom: 15px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Od data:</label>
          <input type="date" id="importDateFrom" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" value="<?php echo date('Y-m-d', strtotime('-1 month')); ?>">
        </div>
        <div style="margin-bottom: 20px;">
          <label style="display: block; margin-bottom: 8px; font-weight: 500; color: #475569;">Do data:</label>
          <input type="date" id="importDateTo" class="input" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" value="<?php echo date('Y-m-d'); ?>">
        </div>
      </div>
      
      <div id="importProgress" style="display: none; margin-bottom: 20px;">
        <div style="width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
          <div id="importProgressBar" style="height: 100%; background: linear-gradient(90deg, #059669, #16a34a); width: 0%; transition: width 0.3s;"></div>
        </div>
        <div id="importStatus" style="margin-top: 10px; color: #64748b; font-size: 14px;"></div>
      </div>
      
      <div id="importResult" style="display: none; padding: 12px; border-radius: 8px; margin-bottom: 20px;"></div>
      
      <div style="display: flex; gap: 10px; justify-content: flex-end;">
        <button type="button" class="btn btn-light" onclick="closeImportDialog()">Zrušit</button>
        <button type="button" class="btn btn-primary" onclick="startImport()">
          <span id="importBtnText">🚀 Importovat</span>
        </button>
      </div>
      
      <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
        <p style="margin: 0; font-size: 12px; color: #94a3b8;">
          <strong>Tip:</strong> Funkce používá Google Finance API podobně jako =GOOGLEFINANCE() v Google Sheets.
          Podporované jsou akcie z hlavních burz (NYSE, NASDAQ, LSE, atd.).
        </p>
      </div>
    </div>
  </div>
  
  <!-- Market Data Table -->
  <div class="content-box">
    <div class="table-scroll-top"><div style="width: 1600px; height: 1px;"></div></div>
    <div class="table-container">
      <table class="table" id="marketTable">
        <thead>
          <tr>
            <th class="w-ticker" data-key="ticker" data-type="text">
              <span class="th-sort">Ticker <span class="dir"></span></span>
            </th>
            <th data-key="company_name" data-type="text">
              <span class="th-sort">Název společnosti <span class="dir"></span></span>
            </th>
            <th data-key="exchange" data-type="text">
              <span class="th-sort">Burza <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="current_price" data-type="number">
              <span class="th-sort">Cena <span class="dir"></span></span>
            </th>
            <th class="w-price num" data-key="change_amount" data-type="number">
              <span class="th-sort">Změna <span class="dir"></span></span>
            </th>
            <th data-key="change_percent" data-type="number">
              <span class="th-sort">Změna % <span class="dir"></span></span>
            </th>
            
            <!-- NOVÉ SLOUPCE -->
            <th data-key="all_time_high" data-type="number" title="Pokles od maxima / Růst od minima">
                <span class="th-sort">Rozsah (ATH/ATL) <span class="dir"></span></span>
            </th>
            <th data-key="ema_212" data-type="number" title="Vzdálenost od EMA 212">
                <span class="th-sort">Trend (EMA) <span class="dir"></span></span>
            </th>
            <th data-key="resilience_score" data-type="number" title="Phoenix: Pád >60%, následný návrat k 70% maxima">
                <span class="th-sort">Odolnost 🛡️ <span class="dir"></span></span>
            </th>

            <th data-key="dividend_yield" data-type="number">
              <span class="th-sort">Dividenda <span class="dir"></span></span>
            </th>
            <th data-key="age_minutes" data-type="number">
              <span class="th-sort">Aktualizace <span class="dir"></span></span>
            </th>
            <th>Akce</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="11" style="text-align: center; padding: 40px; color: #94a3b8;">
                Žádná data k zobrazení
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r):
              $age = (int)($r['age_minutes'] ?? 999);
              $ageBadgeClass = $age <= 10 ? 'fresh' : ($age <= 60 ? '' : 'stale');
              
              // Logika pro výpočet změny, pokud chybí z DB
              $currentPrice = (float)($r['current_price'] ?? 0);
              $changePercent = (float)($r['change_percent'] ?? 0);
              $changeAmount = (float)($r['current_price'] * ($changePercent / 100)); // přibližný odhad pokud chybí
              
              // Pokud máme včerejší cenu a current calc je 0 (nebo null), dopočítáme přesně
              // Prioritizujeme 'previous_close' (z Yahoo Summary), poté 'last_close_price' (z DB historie)
              $prevData = (float)($r['previous_close'] ?? 0);
              $prevHist = (float)($r['last_close_price'] ?? 0);
              $prev = $prevData > 0 ? $prevData : $prevHist;

              if (abs($changePercent) < 0.001 && $prev > 0 && $currentPrice > 0) {
                  $changeAmount = $currentPrice - $prev;
                  $changePercent = ($changeAmount / $prev) * 100;
              }
            ?>
              <tr
                onclick="openHistoryModal('<?php echo htmlspecialchars($r['id']); ?>', '<?php echo htmlspecialchars(addslashes($r['company_name'] ?? '')); ?>')"
                style="cursor: pointer;"
                data-ticker="<?php echo htmlspecialchars($r['id']); ?>"
                data-company_name="<?php echo htmlspecialchars($r['company_name'] ?? ''); ?>"
                data-exchange="<?php echo htmlspecialchars($r['exchange'] ?? ''); ?>"
                data-current_price="<?php echo $currentPrice; ?>"
                data-change_amount="<?php echo $changeAmount; ?>"
                data-change_percent="<?php echo $changePercent; ?>"
                data-volume="<?php echo (float)($r['volume'] ?? 0); ?>"
                data-market_cap="<?php echo (float)($r['market_cap'] ?? 0); ?>"
                data-pe_ratio="<?php echo (float)($r['pe_ratio'] ?? 0); ?>"
                data-dividend_yield="<?php echo (float)($r['dividend_yield'] ?? 0); ?>"
                data-age_minutes="<?php echo $age; ?>"
                data-all_time_high="<?php echo ($r['all_time_high'] > 0 && $currentPrice > 0) ? (($currentPrice - $r['all_time_high']) / $r['all_time_high']) * 100 : -9999; ?>"
                data-ema_212="<?php echo (!empty($r['ema_212']) && $r['ema_212'] > 0 && $currentPrice > 0) ? ((($currentPrice - $r['ema_212']) / $r['ema_212']) * 100) : -9999; ?>"
                data-resilience_score="<?php echo (int)($r['resilience_score'] ?? 0); ?>"
              >
                <td>
                  <div class="ticker-cell" style="display:flex;align-items:center;">
                    <span class="watch-star <?php echo !empty($r['track_history'])?'active':''; ?>" onclick="toggleWatch('<?php echo $r['id']; ?>', this)" title="Sledovat historii">★</span>
                    <?php echo htmlspecialchars($r['id']); ?>
                  </div>
                </td>
                <td>
                  <?php if ($r['company_name']): ?>
                    <div class="company-name"><?php echo htmlspecialchars($r['company_name']); ?></div>
                  <?php else: ?>
                    <span style="color: #cbd5e1;">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['exchange']): ?>
                    <span class="badge-exchange"><?php echo htmlspecialchars($r['exchange']); ?></span>
                  <?php else: ?>
                    <span style="color: #cbd5e1;">—</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <strong><?php echo market_fmtNum($r['current_price'], 2); ?></strong>
                  <span style="color: #94a3b8; font-size: 11px;"><?php echo htmlspecialchars($r['currency'] ?? ''); ?></span>
                </td>
                <?php
                  // Určení trendu
                  $trendClass = '';
                  $trendIcon = '';
                  $sign = '';
                  if ($changePercent > 0) {
                      $trendClass = 'text-success'; // Zelená
                      $trendIcon = '↑';
                      $sign = '+';
                  } elseif ($changePercent < 0) {
                      $trendClass = 'text-danger'; // Červená
                      $trendIcon = '↓';
                      $sign = '';
                  }
                ?>
                <td class="num <?php echo $trendClass; ?>" style="font-weight: 500;">
                  <?php echo $changeAmount != 0 ? $sign . market_fmtNum($changeAmount, 2) : '—'; ?>
                </td>
                <td class="num <?php echo $trendClass; ?>" style="font-weight: 600;">
                  <?php if ($changePercent != 0): ?>
                    <?php echo $sign . market_fmtNum($changePercent, 2); ?>% <?php echo $trendIcon; ?>
                  <?php elseif ($prev <= 0 && empty($r['change_percent'])): ?>
                     <span title="Chybí historie. Stáhněte data." style="color:#cbd5e1; cursor:help; font-size: 11px;">(No History)</span>
                  <?php else: ?>
                    0.00%
                  <?php endif; ?>
                </td>
                
                <!-- ATH / ATL Stats -->
                <!-- ATH / ATL Stats -->
                <td style="font-size: 12px; line-height: 1.2;" data-val="<?php echo $r['all_time_high'] > 0 ? (($currentPrice - $r['all_time_high']) / $r['all_time_high']) * 100 : -9999; ?>">
                    <?php if (!empty($r['all_time_high']) && $r['all_time_high'] > 0 && $currentPrice > 0): 
                        $athDiff = (($currentPrice - $r['all_time_high']) / $r['all_time_high']) * 100;
                        $atlDiff = (!empty($r['all_time_low']) && $r['all_time_low'] > 0) ? (($currentPrice - $r['all_time_low']) / $r['all_time_low']) * 100 : 0;
                    ?>
                       <?php if ($athDiff >= -0.1): ?>
                           <div style="color:#10b981; font-weight:600;" title="Old ATH: <?php echo market_fmtNum($r['all_time_high']); ?>">
                             🚀 +<?php echo market_fmtNum($athDiff, 1); ?>% (nad ATH)
                           </div>
                       <?php else: ?>
                           <div style="color:#ef4444;" title="ATH: <?php echo market_fmtNum($r['all_time_high']); ?>">
                             ↓ <?php echo market_fmtNum($athDiff, 1); ?>% (od Max)
                           </div>
                       <?php endif; ?>

                       <div style="color:#84cc16; font-size:11px;" title="ATL: <?php echo market_fmtNum($r['all_time_low']); ?>">
                         ↑ <?php echo market_fmtNum($atlDiff, 1); ?>% (od Min)
                       </div>
                    <?php else: ?>
                       <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>

                <!-- EMA 212 Trend -->
                <td style="font-size: 13px;" data-val="<?php echo (!empty($r['ema_212']) && $r['ema_212'] > 0 && $currentPrice > 0) ? ((($currentPrice - $r['ema_212']) / $r['ema_212']) * 100) : -9999; ?>">
                    <?php if (!empty($r['ema_212']) && $r['ema_212'] > 0 && $currentPrice > 0): 
                        $emaDiff = (($currentPrice - $r['ema_212']) / $r['ema_212']) * 100;
                        $emaColor = $emaDiff >= 0 ? 'text-success' : 'text-danger';
                        $emaSign = $emaDiff >= 0 ? '+' : '';
                    ?>
                       <div class="<?php echo $emaColor; ?>" style="font-weight:600;">
                         <?php echo $emaSign . market_fmtNum($emaDiff, 1); ?>%
                       </div>
                       <div style="color:#94a3b8; font-size:11px;">
                         EMA: <?php echo market_fmtNum($r['ema_212'], 0); ?>
                       </div>
                    <?php else: ?>
                       <span style="color:#cbd5e1;">—</span>
                    <?php endif; ?>
                </td>

                <!-- Resilience Score -->
                <td data-val="<?php echo $r['resilience_score'] ?? 0; ?>">
                    <?php 
                      $sc = $r['resilience_score'] ?? 0;
                      if ($sc > 0) {
                          echo '<span class="badge bg-warning text-dark" title="Titán">🛡️ ' . $sc . 'x</span>';
                      } else {
                          echo '<span style="color:#cbd5e1; font-size:12px;">—</span>';
                      }
                    ?>
                </td>

                <td class="num" style="color: #64748b;">
                  <?php if ($r['dividend_yield'] !== null && $r['dividend_yield'] > 0): ?>
                    <?php echo market_fmtNum($r['dividend_yield'], 2); ?>%
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <span class="age-badge <?php echo $ageBadgeClass; ?>">
                    <?php
                      if ($age < 60) echo $age . ' min';
                      elseif ($age < 1440) echo round($age/60) . ' hod';
                      else echo round($age/1440) . ' dní';
                    ?>
                  </span>
                </td>
                <td>
                   <button type="button" class="btn btn-sm btn-light" style="border: 1px solid #cbd5e1; color:#0f172a;" onclick="openHistoryModal('<?php echo htmlspecialchars($r['id']); ?>', '<?php echo htmlspecialchars(addslashes($r['company_name'] ?? '')); ?>'); event.stopPropagation();">
                     📈 Graf
                   </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  
  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php
        $base = $_GET;
        unset($base['page']);
        $mk = function($p) use ($base) {
          $base['page'] = $p;
          return 'market.php?' . http_build_query($base);
        };
      ?>
      
      <?php if ($page > 1): ?>
        <a href="<?php echo htmlspecialchars($mk($page - 1)); ?>">&laquo; Předchozí</a>
      <?php else: ?>
        <span class="muted">&laquo; Předchozí</span>
      <?php endif; ?>
      
      <?php for ($p = max(1, $page - 3); $p <= min($totalPages, $page + 3); $p++): ?>
        <?php if ($p === $page): ?>
          <span class="active"><?php echo $p; ?></span>
        <?php else: ?>
          <a href="<?php echo htmlspecialchars($mk($p)); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      
      <?php if ($page < $totalPages): ?>
        <a href="<?php echo htmlspecialchars($mk($page + 1)); ?>">Další &raquo;</a>
      <?php else: ?>
        <span class="muted">Další &raquo;</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  

  
</main>

<script>
/* Sorting functionality for market table */
(function() {
  const table = document.getElementById('marketTable');
  if (!table) return;
  
  const thead = table.querySelector('thead');
  const tbody = table.querySelector('tbody');
  let currentKey = null;
  let currentDir = 1;
  
  function getVal(tr, key, type) {
    const raw = tr.dataset[key];
    if (type === 'number') {
      const n = parseFloat(raw);
      return isNaN(n) ? 0 : n;
    }
    if (type === 'date') {
      const t = Date.parse(raw);
      return isNaN(t) ? 0 : t;
    }
    return (raw || '').toString().toLowerCase();
  }
  
  function setIndicator(th, dir) {
    thead.querySelectorAll('th .dir').forEach(s => s.textContent = '');
    const span = th.querySelector('.dir');
    if (span) span.textContent = dir === 1 ? '▲' : '▼';
  }
  
  thead.addEventListener('click', function(e) {
    const th = e.target.closest('th');
    if (!th) return;
    const key = th.dataset.key;
    const type = th.dataset.type || 'text';
    if (!key) return;
    if (currentKey === key) currentDir *= -1; else { currentKey = key; currentDir = 1; }
    setIndicator(th, currentDir);
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
      const va = getVal(a, key, type);
      const vb = getVal(b, key, type);
      if (va < vb) return -1 * currentDir;
      if (va > vb) return 1 * currentDir;
      return 0;
    });
    rows.forEach(r => tbody.appendChild(r));
  });
})();

/* Auto submit on filter change */
(function() {
  const form = document.getElementById('filterForm');
  if (form) {
      form.querySelectorAll('select').forEach(s => s.addEventListener('change', () => form.submit()));
  }
})();

/* Scroll Sync */
(function() {
  const scrollTop = document.querySelector('.table-scroll-top');
  const container = document.querySelector('.table-container');
  if (scrollTop && container) {
    const table = container.querySelector('table');
    if (table) {
      const innerDiv = scrollTop.querySelector('div');
      if (innerDiv) innerDiv.style.width = table.scrollWidth + 'px';
    }
    scrollTop.addEventListener('scroll', function() { container.scrollLeft = scrollTop.scrollLeft; });
    container.addEventListener('scroll', function() { scrollTop.scrollLeft = container.scrollLeft; });
  }
})();
</script>

<!-- Import Dialog -->
<div id="importDialog" class="modal-overlay" style="display:none;">
<div class="modal-content" style="max-width: 500px;">
  <div class="modal-header">
    <h3>📥 Import Dat</h3>
    <button class="modal-close" onclick="closeImportDialog()">×</button>
  </div>
  <div class="modal-body">
    <div style="margin-bottom:15px;">
        <label>Ticker symbol</label>
        <div style="display:flex; gap:5px;">
            <input type="text" id="importTicker" class="input" placeholder="např. AAPL, BTC-USD" style="flex:1;">
            <button class="btn btn-secondary" onclick="openTickerLookup('importTicker')" title="Vyhledat v DB">🔍</button>
        </div>
    </div>
    <div id="importProgress" style="display:none; margin-top:15px;">
        <div class="progress-bar" style="height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;"><div id="importProgressBar" style="width:0%; height:100%; background:#3b82f6; transition:width 0.3s;"></div></div>
        <div id="importStatus" style="font-size:12px; margin-top:5px; color:#64748b;"></div>
    </div>
    <div id="importResult" style="display:none; margin-top:15px;"></div>
  </div>
  <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeImportDialog()">Zrušit</button>
      <button class="btn btn-primary" onclick="startImport()"><span id="importBtnText">🚀 Importovat</span></button>
  </div>
</div>
</div>

<!-- Watchlist Manager Modal -->
<div id="watchManagerModal" class="modal-overlay" style="display:none;">
<div class="modal-content" style="max-width: 800px; height: 80vh; display:flex; flex-direction:column;">
  <div class="modal-header">
    <h3>🗂️ Správce sledovaných titulů</h3>
    <button class="modal-close" onclick="closeWatchManager()">×</button>
  </div>
  <div class="modal-body" style="flex:1; overflow:hidden; display:flex; flex-direction:column; padding:0;">
    <div style="padding: 15px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display:flex; gap:10px;">
       <input type="text" id="wmSearch" class="input" placeholder="Hledat ticker nebo název (Enter pro hledání v DB)..." style="flex:1;">
       <button class="btn btn-secondary" onclick="wmFetchData()">Hledat v DB</button>
    </div>
    <div style="padding: 0 15px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0;">
         <table class="table" style="margin:0;">
             <thead>
                 <tr>
                     <th width="50"><input type="checkbox" onchange="wmToggleAll(this)"></th>
                     <th width="100" onclick="wmSort('id')" style="cursor:pointer">Ticker ↕</th>
                     <th onclick="wmSort('company_name')" style="cursor:pointer">Název společnosti ↕</th>
                     <th width="100">Typ</th>
                     <th width="80">Stav</th>
                 </tr>
             </thead>
         </table>
    </div>
    <div style="flex:1; overflow-y:auto; padding:0 15px;">
         <table class="table" style="margin:0;">
             <tbody id="wmTableBody"></tbody>
         </table>
         <div id="wmLoading" style="text-align:center; padding:20px; display:none; color:#64748b;">Načítám data...</div>
    </div>
  </div>
  <div class="modal-footer" style="padding: 15px; border-top: 1px solid #e2e8f0; background: #f8fafc; display:flex; justify-content:space-between; align-items:center;">
    <span id="wmStatus" style="font-size:13px; color:#64748b;">Načteno 0 položek.</span>
    <div>
        <button class="btn btn-secondary" onclick="closeWatchManager()">Zavřít</button>
        <button class="btn btn-primary" onclick="wmSaveChanges()">Uložit změny</button>
    </div>
  </div>
</div>
</div>

<!-- History Download Modal -->
<div id="downloadModal" class="modal-overlay" style="display:none;">
<div class="modal-content" style="max-width: 400px; padding:0;">
<div class="modal-header">
<h3>⬇️ Stáhnout data</h3>
<button class="modal-close" onclick="closeDownloadModal()">×</button>
</div>
<div class="modal-body" style="padding: 25px;">
<div style="margin-bottom:20px;">
    <label style="display:block; margin-bottom:8px; font-weight:600; font-size:13px; color:#1e293b;">Období</label>
    <select id="histPeriod" class="input" style="width:100%;">
        <option value="max">Vše (MAX)</option>
        <option value="5y">5 let</option>
        <option value="1y">1 rok</option>
        <option value="YTD">YTD</option>
    </select>
</div>
<div style="text-align:right;">
     <button class="btn btn-secondary" onclick="closeDownloadModal()">Zrušit</button>
     <button class="btn btn-primary" onclick="submitHistoryDownload()">Stáhnout</button>
</div>
</div>
</div>
</div>

<!-- Chart Modal -->
<div id="chartModal" class="modal-overlay" style="display: none;">
  <div class="modal-content" style="max-width: 800px; padding:0;">
    <div class="modal-header">
      <h3>📈 <span id="chartModalTitle">Ticker</span></h3>
      <button class="modal-close" onclick="document.getElementById('chartModal').style.display='none'">✕</button>
    </div>
    <div class="modal-body" style="padding: 20px;">
        <div style="display:flex; gap:10px; margin-bottom:15px; align-items:center;">
           <button class="btn btn-sm btn-light" onclick="renderChartFiltered('1y')">1 Rok</button>
           <button class="btn btn-sm btn-light" onclick="renderChartFiltered('5y')">5 Let</button>
           <button class="btn btn-sm btn-light" onclick="renderChartFiltered('max')">Max</button>
           <div style="flex:1;"></div>
           <button class="btn btn-secondary btn-sm" onclick="openDownloadModalFromChart()">⬇️ Stáhnout data</button>
        </div>
        <div style="height: 350px; position: relative;">
          <canvas id="historyChart"></canvas>
        </div>
        <div id="histResult" style="margin-top:10px; text-align:right;"></div>
    </div>
  </div>
</div>

<!-- Ticker Lookup Modal (Sub-dialog) -->
<div id="tickerLookupModal" class="modal-overlay" style="display:none; z-index: 10000;">
  <div class="modal-content" style="max-width: 400px;">
    <div class="modal-header">
      <h3>🔍 Najít Ticker</h3>
      <button class="modal-close" onclick="closeTickerLookup()">×</button>
    </div>
    <div class="modal-body">
       <input type="text" id="lookupInput" class="input" placeholder="Název firmy..." onkeyup="if(event.key==='Enter') performLookup()">
       <button class="btn btn-secondary" style="margin-top:5px; width:100%;" onclick="performLookup()">Hledat</button>
       <div id="lookupResults" style="margin-top:15px; max-height:200px; overflow-y:auto;"></div>
       <div style="margin-top:10px; font-size:12px; color:#64748b;">
          Tip: Pokud nenaleznete zde, vyhledejte ticker na <a href="https://www.google.com/finance" target="_blank">Google Finance</a> a zkopírujte ho.
       </div>
    </div>
  </div>
</div>

<script>
/* Watch Manager Logic */
let wmRowsData=[]; let wmOriginalState={}; let wmChanges={}; let wmSortKey='is_watched'; let wmSortDir=-1; 
function openWatchManager(){document.getElementById('watchManagerModal').style.display='flex';wmFetchData();}
function closeWatchManager(){document.getElementById('watchManagerModal').style.display='none';wmChanges={};}
function wmFetchData(){
    const q=document.getElementById('wmSearch').value.trim();
    document.getElementById('wmLoading').style.display='block'; document.getElementById('wmTableBody').innerHTML='';
    fetch('ajax-manage-watchlist.php',{method:'POST',body:JSON.stringify({action:'get_candidates',q:q})})
    .then(r=>r.json()).then(res=>{
        document.getElementById('wmLoading').style.display='none';
        if(res.success){ wmRowsData=res.data;wmOriginalState={};wmChanges={};wmRowsData.forEach(r=>wmOriginalState[r.id]=(r.is_watched==1));renderWmTable();}
        else alert('Chyba: '+res.error);
    }).catch(e=>{document.getElementById('wmLoading').style.display='none';alert('Chyba komunikace.');});
}
function renderWmTable(){
    const tbody=document.getElementById('wmTableBody');tbody.innerHTML='';
    wmRowsData.sort((a,b)=>{
        let va=a[wmSortKey],vb=b[wmSortKey]; if(wmSortKey==='company_name'||wmSortKey==='id')return va.localeCompare(vb)*wmSortDir; return (va-vb)*wmSortDir;
    });
    let count=0; wmRowsData.forEach(r=>{
        let isWatched=wmOriginalState[r.id]; if(wmChanges.hasOwnProperty(r.id))isWatched=wmChanges[r.id];
        const isOwned=(r.is_owned==1); if(isOwned)isWatched=true; 
        const tr=document.createElement('tr');
        tr.innerHTML=`<td width="50"><input type="checkbox" ${isWatched?'checked':''} ${isOwned?'disabled checked':''} onchange="wmRowChange('${r.id}',this.checked)"></td>
            <td width="100" style="font-weight:600;">${r.id}</td><td><div>${r.company_name||'—'}</div></td>
            <td width="100"><span class="badge-exchange">${r.asset_type||'STOCK'}</span></td>
            <td width="80">${isOwned?'<span style="color:#059669;font-weight:600;font-size:12px;">Vlastněno</span>':(isWatched?'<span style="color:#f59e0b;">★ Sleduji</span>':'<span style="color:#cbd5e1;">—</span>')}</td>`;
        tbody.appendChild(tr); count++;
    });
    document.getElementById('wmStatus').textContent=`Zobrazeno ${count} položek.`;
}
function wmRowChange(id,checked){wmChanges[id]=checked;}
function wmToggleAll(box){const ch=box.checked;wmRowsData.forEach(r=>{if(r.is_owned!=1)wmChanges[r.id]=ch;});renderWmTable();}
function wmSort(k){if(wmSortKey===k)wmSortDir*=-1;else{wmSortKey=k;wmSortDir=1;}renderWmTable();}
function wmSaveChanges(){
    let p=[]; for(let id in wmChanges){if(wmOriginalState[id]!==wmChanges[id])p.push({ticker:id,state:wmChanges[id]});}
    if(p.length===0){alert("Žádné změny.");closeWatchManager();return;}
    const btn=document.querySelector('button[onclick="wmSaveChanges()"]');const old=btn.textContent;btn.textContent='Ukládám...';btn.disabled=true;
    fetch('ajax-manage-watchlist.php',{method:'POST',body:JSON.stringify({action:'batch_update',changes:p})})
    .then(r=>r.json()).then(res=>{
        btn.textContent=old;btn.disabled=false; if(res.success){alert(res.message);location.reload();}else alert('Chyba: '+res.error);
    }).catch(e=>{btn.textContent=old;btn.disabled=false;alert('Chyba komunikace.');});
}
document.getElementById('wmSearch').addEventListener('keyup',function(e){if(e.key==='Enter')wmFetchData();});

/* Import Logic */
function quickImport() {
   const input = document.getElementById('newTickerInput');
   if(!input) { document.getElementById('importDialog').style.display = 'block'; return; }
   const ticker = input.value.trim();
   if (!ticker) { document.getElementById('importDialog').style.display = 'block'; document.getElementById('importTicker').focus(); return; }
   addNewTicker();
}
async function addNewTicker() {
    const input = document.getElementById('newTickerInput'); const t = input.value.trim(); 
    if(!t) { document.getElementById('importDialog').style.display = 'block'; return; }
    const btn = document.querySelector('button[onclick="addNewTicker()"]');
    const oldText = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; btn.disabled=true;
    
    try {
        const r = await fetch('ajax_import_ticker.php', { method: 'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ticker:t.toUpperCase()}) });
        const d = await r.json();
        
        if(d.success) { 
            // Automaticky stáhnout historii a fundamenty
            btn.innerHTML = '<i class="fas fa-cog fa-spin"></i> Stahuji detaily...';
            try {
                await fetch(`ajax-fetch-history.php?ticker=${encodeURIComponent(t.toUpperCase())}&period=max`);
            } catch(e) { console.error('Auto-fetch warning:', e); }

            btn.innerHTML = oldText; btn.disabled = false;
            alert(`✅ ${t.toUpperCase()} Importováno. Cena: $${d.data.price}\n\nDoplňující data (P/E, Dividenda, Graf) byla stažena.`); 
            location.reload(); 
        } else {
            btn.innerHTML = oldText; btn.disabled = false;
            alert('Chyba: '+(d.message||'Error'));
        }
    } catch(e) { 
        btn.innerHTML = oldText; btn.disabled=false; 
        alert('Chyba komunikace.'); 
    }
}
function showImportDialog() { document.getElementById('importDialog').style.display='block'; }
function closeImportDialog() { document.getElementById('importDialog').style.display='none'; }
function startImport() {
  const ticker = document.getElementById('importTicker').value.trim();
  if(!ticker) { alert('Zadejte ticker'); return; }
  document.getElementById('importProgress').style.display='block';
  document.getElementById('importProgressBar').style.width='20%';
  fetch('ajax_import_ticker.php', { method:'POST', body:JSON.stringify({ticker:ticker}) })
  .then(r=>r.json()).then(d => {
      document.getElementById('importProgressBar').style.width='100%';
      if(d.success) { alert('OK'); location.reload(); } else alert('Chyba: '+d.message);
  }).catch(e=>{alert('Error'); document.getElementById('importProgress').style.display='none';});
}
function toggleDateInputs() {
  const type = document.getElementById('importType').value;
  document.getElementById('singleDateInput').style.display = (type === 'date') ? 'block' : 'none';
  document.getElementById('dateRangeInputs').style.display = (type === 'range') ? 'block' : 'none';
}

function updateLivePrices(btn) {
  if (confirm('Spustit aktualizaci?')) {
     const old = btn.innerHTML; btn.disabled = true; btn.textContent = '...';
     fetch('ajax-update-prices.php').then(r=>r.text()).then(t => { alert('Hotovo.'); location.reload(); })
     .catch(e=>{ alert('Chyba'); btn.innerHTML=old; btn.disabled=false; });
  }
}

/* History Chart Logic */
function updateAllHistory(btn) { document.getElementById('downloadModal').style.display = 'flex'; }
function closeDownloadModal() { document.getElementById('downloadModal').style.display = 'none'; }
function submitHistoryDownload() {
    const period = document.getElementById('histPeriod').value;
    const btn = document.querySelector('#downloadModal .btn-primary');
    
    // Determine ticker param (specific or ALL)
    // Determine mode
    let tickerParam = null;
    if (currentHistTicker) {
        tickerParam = currentHistTicker;
    } else {
        tickerParam = 'ALL';
    }

    const oldText = btn.textContent;
    btn.textContent = 'Pracuji...'; 
    btn.disabled = true;
    
    // UI elements
    const progSection = document.getElementById('dlProgressSection') || createProgressSection(); // Fallback creation if missing
    progSection.style.display = 'block';
    const progBar = progSection.querySelector('.progress-bar > div');
    const progStatus = document.getElementById('dlStatus');
    progBar.style.width = '0%';
    progStatus.textContent = 'Připravuji seznam...';
    
    // Helper to run sequential updates
    async function runBatchUpdate() {
        try {
            let tickersToUpdate = [];
            
            if (tickerParam === 'ALL') {
                // Modified: Respect Client-Side Filter
                // Try to find visible rows in the table first
                const visibleRows = document.querySelectorAll('tbody tr[data-ticker]:not([style*="display: none"])');
                
                if (visibleRows.length > 0) {
                    // Extract tickers from visible rows
                    visibleRows.forEach(row => {
                        const t = row.getAttribute('data-ticker');
                        if (t) tickersToUpdate.push(t);
                    });
                } else {
                    // Fallback: If no rows found (or page structure differs), fetch ALL from server
                    const r = await fetch('ajax-fetch-history.php?action=list');
                    const d = await r.json();
                    if(d.success) tickersToUpdate = d.tickers;
                    else throw new Error(d.message);
                }
            } else {
                tickersToUpdate = [tickerParam];
            }

            const total = tickersToUpdate.length;
            let successCount = 0;
            let failCount = 0;

            for (let i = 0; i < total; i++) {
                const t = tickersToUpdate[i];
                const pct = Math.round(((i) / total) * 100);
                progBar.style.width = pct + '%';
                progStatus.textContent = `Stahuji ${t} (${i+1}/${total})...`;
                
                try {
                    const r = await fetch(`ajax-fetch-history.php?ticker=${encodeURIComponent(t)}&period=${period}`);
                    const res = await r.json();
                    if(res.success) {
                        successCount++;
                    } else {
                        failCount++;
                        console.error(`Fetch failed for ${t}:`, res.message);
                        progStatus.textContent = `Chyba u ${t}: ${res.message}`;
                    }
                } catch(e) {
                    failCount++;
                    console.error(`Fetch error for ${t}:`, e);
                    progStatus.textContent = `Chyba spojení u ${t}`;
                }
            }

            progBar.style.width = '100%';
            // If there were errors, show summary but keep the last error visible for a moment
            if (failCount > 0) {
                 btn.textContent = `Hotovo s chybami (${failCount})`;
                 // Don't close immediately if errors
                 setTimeout(() => {
                     // closeDownloadModal(); location.reload(); // Let user see the error
                 }, 3000);
            } else {
                 progStatus.textContent = `Hotovo! OK: ${successCount}`;
                 btn.textContent = 'Hotovo!';
                 setTimeout(() => {
                      closeDownloadModal();
                      location.reload();
                 }, 1000);
            }

        } catch (error) {
            console.error(error);
            progStatus.textContent = 'Chyba: ' + error.message;
            btn.textContent = oldText; 
            btn.disabled = false;
        }
    }

    runBatchUpdate();
}

function createProgressSection() {
    // Finds the modal body and injects progress bar if it doesn't exist
    const modalBody = document.querySelector('#downloadModal .modal-body');
    const div = document.createElement('div');
    div.id = 'dlProgressSection';
    div.style.marginTop = '15px';
    div.innerHTML = `
        <div class="progress-bar" style="height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
            <div style="width:0%; height:100%; background:#10b981; transition:width 0.2s;"></div>
        </div>
        <div id="dlStatus" style="font-size:12px; margin-top:5px; color:#64748b; text-align:center;"></div>
    `;
    modalBody.insertBefore(div, modalBody.lastElementChild); // Insert before footer/buttons
    return div;
}
let currentHistTicker=''; let chartInstance=null; let allChartData={labels:[],data:[],ticker:''};
function toggleWatch(ticker, el, isOwned) {
  if(event) event.stopPropagation();
  if(isOwned) { alert('Vlastněno = sledováno.'); return; }
  const isActive = el.classList.contains('active');
  if(isActive) el.classList.remove('active'); else el.classList.add('active');
  fetch('ajax-manage-watchlist.php', { method: 'POST', body: JSON.stringify({action: 'toggle', ticker: ticker}) })
  .then(r=>r.json()).then(d => { if(!d.success) { if(isActive) el.classList.add('active'); else el.classList.remove('active'); alert('Chyba: ' + (d.error || 'Neznámá chyba')); } });
}
function openHistoryModal(ticker, companyName) {
  currentHistTicker = ticker;
  document.getElementById('chartModalTitle').textContent = companyName ? `${ticker} - ${companyName}` : ticker;
  document.getElementById('chartModal').style.display = 'flex';
  loadChartData();
}
function openDownloadModalFromChart() {
    document.getElementById('chartModal').style.display = 'none';
    document.getElementById('downloadModal').style.display = 'flex';
}
function loadChartData() {
    fetch('ajax-get-chart-data.php?ticker=' + currentHistTicker)
    .then(r=>r.json()).then(d => {
        if(d.success && d.data.length > 0) {
            allChartData = { labels: d.labels, data: d.data, ticker: d.ticker };
            renderChartFiltered('1y');
        } else {
            if(chartInstance) chartInstance.destroy();
        }
    });
}
function renderChartFiltered(period) {
    if(allChartData.data.length === 0) return;
    let cutoffDate = new Date();
    if (period === '1y') cutoffDate.setFullYear(cutoffDate.getFullYear() - 1);
    else if (period === '5y') cutoffDate.setFullYear(cutoffDate.getFullYear() - 5);
    else cutoffDate = new Date(0);
    const cutoffStr = cutoffDate.toISOString().split('T')[0];
    const filteredLabels = [], filteredData = [];
    for (let i = 0; i < allChartData.labels.length; i++) {
        if (allChartData.labels[i] >= cutoffStr) {
            filteredLabels.push(allChartData.labels[i]); filteredData.push(allChartData.data[i]);
        }
    }
    renderChart(filteredLabels.length?filteredLabels:allChartData.labels, filteredData.length?filteredData:allChartData.data, allChartData.ticker);
}
function renderChart(labels, data, ticker) {
    const ctx = document.getElementById('historyChart').getContext('2d');
    if(chartInstance) chartInstance.destroy();
    let color = '#0284c7';
    if (data.length > 1) {
        if (data[data.length - 1] > data[0]) color = '#10b981';
        if (data[data.length - 1] < data[0]) color = '#ef4444';
    }
    chartInstance = new Chart(ctx, { type: 'line', data: { labels: labels, datasets: [{
        label: ticker, data: data, borderColor: color, backgroundColor: 'rgba(0,0,0,0)', borderWidth: 2, pointRadius: 0, hoverRadius: 4, tension: 0.1
    }]}, options: { responsive: true, maintainAspectRatio: false, interaction: {mode:'index',intersect:false}, scales:{x:{display:true,ticks:{maxTicksLimit:8}}}, plugins:{legend:{display:false}} } });
}

/* Lookup Logic */
let lookupTargetInputId = null;
function openTickerLookup(targetId) {
    lookupTargetInputId = targetId;
    document.getElementById('tickerLookupModal').style.display = 'flex';
    document.getElementById('lookupInput').value = '';
    document.getElementById('lookupResults').innerHTML = '';
    document.getElementById('lookupInput').focus();
}
function closeTickerLookup() {
    document.getElementById('tickerLookupModal').style.display = 'none';
}
function performLookup() {
    const q = document.getElementById('lookupInput').value.trim();
    if (q.length < 2) return;
    document.getElementById('lookupResults').innerHTML = 'Hledám...';
    fetch('ajax-manage-watchlist.php', { method: 'POST', body: JSON.stringify({ action: 'get_candidates', q: q }) })
    .then(r => r.json())
    .then(res => {
         const div = document.getElementById('lookupResults');
         div.innerHTML = '';
         if (res.success && res.data.length > 0) {
             const ul = document.createElement('ul');
             ul.style.listStyle = 'none'; ul.style.padding = 0;
             res.data.forEach(item => {
                 const li = document.createElement('li');
                 li.style.padding = '8px'; li.style.borderBottom = '1px solid #eee'; li.style.cursor = 'pointer';
                 li.innerHTML = `<strong>${item.id}</strong> - ${item.company_name}`;
                 li.onclick = () => selectTickerResult(item.id);
                 li.onmouseover = () => li.style.background = '#f1f5f9';
                 li.onmouseout = () => li.style.background = 'transparent';
                 ul.appendChild(li);
             });
             div.appendChild(ul);
         } else {
             div.innerHTML = 'Žádné výsledky v lokální DB.';
         }
    })
    .catch(e => document.getElementById('lookupResults').innerHTML = 'Chyba serveru.');
}
function selectTickerResult(ticker) {
    if (lookupTargetInputId) {
        const inp = document.getElementById(lookupTargetInputId);
        if (inp) inp.value = ticker;
    }
    closeTickerLookup();
}
</script>
<script src="js/collapsible.js"></script>
<script>
/* Table Filter Initialization */
document.addEventListener('DOMContentLoaded', () => {
    // Column indices to skip: 10 (Age/Updated), 11 (Actions)
    const tf = new TableFilter('marketTable', {
        excludeCols: [11], // Actions column
        quickFilterId: 'quickFilter' // Bind new D365 filter input
    });
    tf.init();
});
</script>
</body>
</html>
