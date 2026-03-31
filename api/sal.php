<?php
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) { header("Location: ../index.html"); exit; }

/* ===== Resolve User ID (robust) ===== */
function resolveUserIdFromSession() {
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
list($currentUserId,) = resolveUserIdFromSession();
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : (isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Uživatel');
$currentPage = 'sal';

/* ===== DB ===== */
$pdo=null;
try {
  $paths=[__DIR__.'/../env.local.php',__DIR__.'/env.local.php',__DIR__.'/php/env.local.php','../env.local.php','php/env.local.php','../php/env.local.php'];
  foreach($paths as $p){ if(file_exists($p)){ require_once $p; break; } }
  if(defined('DB_HOST')){
    $pdo=new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
  } else { throw new Exception('DB config nenalezen'); }
} catch(Exception $e){ /* Silent UI; design bez debugů */ }

/* ===== Filters ===== */
$symbol     = trim($_GET['symbol'] ?? '');
$currency   = trim($_GET['currency'] ?? '');
$platform   = trim($_GET['platform'] ?? '');
$dateFrom   = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo     = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$groupMode  = isset($_GET['cons']) ? $_GET['cons'] : 'none';
$grouped    = ($groupMode !== 'none');
$yearFilter = isset($_GET['year']) ? trim($_GET['year']) : '';
$taxTestFilter = isset($_GET['tax_test']) ? trim($_GET['tax_test']) : '';

/* ===== Helper Functions ===== */
function calculateAveragePurchasePrice($pdo, $userId, $ticker, $sellDate, $platform = null) {
  // Získat všechny nákupy před datem prodeje
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
  
  // Získat všechny prodeje před aktuálním prodejem
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
  
  // Vypočítat zbývající množství po předchozích prodejích
  $totalBought = 0;
  $totalCostCZK = 0;
  $totalSold = 0;
  $weightedExRate = 0;
  $totalCostOriginal = 0;
  
  foreach ($purchases as $p) {
    $totalBought += (float)$p['amount'];
    $totalCostCZK += abs((float)$p['amount_czk']); // abs protože nákupy mohou být záporné
    
    // Pro výpočet průměrného kurzu
    $costInOriginal = (float)$p['amount'] * (float)$p['price'];
    $totalCostOriginal += $costInOriginal;
    $weightedExRate += $costInOriginal * (float)$p['ex_rate'];
  }
  
  foreach ($previousSells as $s) {
    $totalSold += (float)$s['amount'];
  }
  
  $remainingQty = $totalBought - $totalSold;
  
  if ($remainingQty <= 0) {
    return [
      'avg_price_czk' => 0,
      'avg_price_original' => 0,
      'avg_ex_rate' => 0,
      'total_qty' => 0,
      'first_purchase_date' => null
    ];
  }
  
  // Průměrná cena
  $avgPriceCZK = $totalCostCZK / $totalBought;
  $avgPriceOriginal = $totalCostOriginal / $totalBought;
  $avgExRate = $totalCostOriginal > 0 ? $weightedExRate / $totalCostOriginal : 0;
  
  // Najít datum prvního nákupu pro daňový test
  $firstPurchaseDate = !empty($purchases) ? $purchases[0]['date'] : null;
  
  return [
    'avg_price_czk' => $avgPriceCZK,
    'avg_price_original' => $avgPriceOriginal,
    'avg_ex_rate' => $avgExRate,
    'total_qty' => $remainingQty,
    'first_purchase_date' => $firstPurchaseDate
  ];
}

function checkTaxTest($buyDate, $sellDate) {
  if (!$buyDate || !$sellDate) return false;
  
  $buy = new DateTime($buyDate);
  $sell = new DateTime($sellDate);
  $diff = $buy->diff($sell);
  
  // 3 roky = 1095 dní (3 * 365)
  $days = $diff->days;
  return $days >= 1095;
}

/* ===== Get Sales Data ===== */
$sales = [];
$salesAnalysis = [];

if ($pdo && $currentUserId) {
  // Získat všechny prodeje
  $sql = "SELECT trans_id, date, id, amount, price, ex_rate, amount_cur, currency, amount_czk, platform, fees, notes
          FROM broker_trans 
          WHERE user_id = ? 
          AND trans_type = 'Sell' 
          AND (product_type = 'Stock' OR product_type = 'Crypto')";
  $params = [$currentUserId];
  
  if($symbol !== '') { $sql .= " AND id = ?"; $params[] = $symbol; }
  if($currency !== '') { $sql .= " AND currency = ?"; $params[] = $currency; }
  if($platform !== '') { $sql .= " AND platform = ?"; $params[] = $platform; }
  if($dateFrom !== '') { $sql .= " AND date >= ?"; $params[] = $dateFrom; }
  if($dateTo !== '') { $sql .= " AND date <= ?"; $params[] = $dateTo; }
  if($yearFilter !== '') { $sql .= " AND YEAR(date) = ?"; $params[] = $yearFilter; }
  
  $sql .= " ORDER BY date DESC, trans_id DESC";
  
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $sales = $stmt->fetchAll();
  
  // Analyzovat každý prodej
  foreach ($sales as $sale) {
    $avgData = calculateAveragePurchasePrice($pdo, $currentUserId, $sale['id'], $sale['date'], $sale['platform']);
    
    $sellQty = (float)$sale['amount'];
    $sellPriceCZK = abs((float)$sale['amount_czk']) / $sellQty; // Cena za kus v CZK
    $sellPriceOriginal = (float)$sale['price'];
    
    // Výpočet zisku/ztráty
    $profitCZK = ($sellPriceCZK - $avgData['avg_price_czk']) * $sellQty;
    $profitOriginal = ($sellPriceOriginal - $avgData['avg_price_original']) * $sellQty;
    
    // Daňový test
    $taxTestPassed = checkTaxTest($avgData['first_purchase_date'], $sale['date']);
    
    // Filtr na daňový test
    if ($taxTestFilter !== '') {
      if ($taxTestFilter === 'yes' && !$taxTestPassed) continue;
      if ($taxTestFilter === 'no' && $taxTestPassed) continue;
    }
    
    $salesAnalysis[] = [
      'trans_id' => $sale['trans_id'],
      'date' => $sale['date'],
      'id' => $sale['id'],
      'platform' => $sale['platform'],
      'currency' => $sale['currency'],
      'sell_qty' => $sellQty,
      'sell_price_czk' => $sellPriceCZK,
      'sell_price_original' => $sellPriceOriginal,
      'sell_total_czk' => abs((float)$sale['amount_czk']),
      'sell_total_original' => abs((float)$sale['amount_cur']),
      'avg_buy_price_czk' => $avgData['avg_price_czk'],
      'avg_buy_price_original' => $avgData['avg_price_original'],
      'avg_ex_rate' => $avgData['avg_ex_rate'],
      'sell_ex_rate' => (float)$sale['ex_rate'],
      'profit_czk' => $profitCZK,
      'profit_original' => $profitOriginal,
      'profit_percent' => $avgData['avg_price_czk'] > 0 ? ($profitCZK / ($avgData['avg_price_czk'] * $sellQty)) * 100 : 0,
      'fees' => (float)$sale['fees'],
      'net_profit_czk' => $profitCZK - (float)$sale['fees'],
      'tax_test_passed' => $taxTestPassed,
      'first_purchase_date' => $avgData['first_purchase_date'],
      'holding_days' => $avgData['first_purchase_date'] ? 
        (new DateTime($avgData['first_purchase_date']))->diff(new DateTime($sale['date']))->days : 0,
      'notes' => $sale['notes']
    ];
  }
}

/* ===== Aggregation ===== */
$rows_grouped = [];
$totalStats = [
  'total_sales' => 0,
  'total_profit_czk' => 0,
  'total_loss_czk' => 0,
  'total_fees' => 0,
  'total_net_profit' => 0,
  'tax_free_profit' => 0,
  'taxable_profit' => 0,
  'winning_trades' => 0,
  'losing_trades' => 0
];

if ($grouped && !empty($salesAnalysis)) {
  $groups = [];
  
  foreach ($salesAnalysis as $s) {
    if ($groupMode === 'ticker') {
      $key = $s['id'];
      $label = $s['id'];
    } elseif ($groupMode === 'currency') {
      $key = $s['currency'];
      $label = $s['currency'];
    } elseif ($groupMode === 'platform') {
      $key = $s['platform'];
      $label = $s['platform'];
    } elseif ($groupMode === 'year') {
      $key = date('Y', strtotime($s['date']));
      $label = 'Rok ' . $key;
    } elseif ($groupMode === 'month') {
      $key = date('Y-m', strtotime($s['date']));
      $label = date('m/Y', strtotime($s['date']));
    } elseif ($groupMode === 'tax_status') {
      $key = $s['tax_test_passed'] ? 'tax_free' : 'taxable';
      $label = $s['tax_test_passed'] ? 'Osvobozené (3+ roky)' : 'Zdanitelné (<3 roky)';
    } else { /* all */
      $key = '__ALL__';
      $label = 'Celkem';
    }
    
    if (!isset($groups[$key])) {
      $groups[$key] = [
        'label' => $label,
        'trades_count' => 0,
        'total_sold_czk' => 0,
        'total_bought_czk' => 0,
        'profit_czk' => 0,
        'loss_czk' => 0,
        'net_profit_czk' => 0,
        'fees' => 0,
        'winning_trades' => 0,
        'losing_trades' => 0,
        'tax_free_trades' => 0,
        'taxable_trades' => 0,
        'tax_free_profit' => 0,
        'taxable_profit' => 0,
        'first_trade' => $s['date'],
        'last_trade' => $s['date']
      ];
    }
    
    $g =& $groups[$key];
    $g['trades_count']++;
    $g['total_sold_czk'] += $s['sell_total_czk'];
    $g['total_bought_czk'] += $s['avg_buy_price_czk'] * $s['sell_qty'];
    $g['fees'] += $s['fees'];
    
    if ($s['profit_czk'] > 0) {
      $g['profit_czk'] += $s['profit_czk'];
      $g['winning_trades']++;
    } else {
      $g['loss_czk'] += abs($s['profit_czk']);
      $g['losing_trades']++;
    }
    
    if ($s['tax_test_passed']) {
      $g['tax_free_trades']++;
      $g['tax_free_profit'] += $s['profit_czk'];
    } else {
      $g['taxable_trades']++;
      $g['taxable_profit'] += $s['profit_czk'];
    }
    
    $g['net_profit_czk'] = $g['profit_czk'] - $g['loss_czk'] - $g['fees'];
    
    // Aktualizace dat
    if ($s['date'] < $g['first_trade']) $g['first_trade'] = $s['date'];
    if ($s['date'] > $g['last_trade']) $g['last_trade'] = $s['date'];
    
    unset($g);
  }
  
  $rows_grouped = array_values($groups);
}

// Celkové statistiky
foreach ($salesAnalysis as $s) {
  $totalStats['total_sales']++;
  $totalStats['total_fees'] += $s['fees'];
  
  if ($s['profit_czk'] > 0) {
    $totalStats['total_profit_czk'] += $s['profit_czk'];
    $totalStats['winning_trades']++;
  } else {
    $totalStats['total_loss_czk'] += abs($s['profit_czk']);
    $totalStats['losing_trades']++;
  }
  
  if ($s['tax_test_passed']) {
    $totalStats['tax_free_profit'] += $s['profit_czk'];
  } else {
    $totalStats['taxable_profit'] += $s['profit_czk'];
  }
}

$totalStats['total_net_profit'] = $totalStats['total_profit_czk'] - $totalStats['total_loss_czk'] - $totalStats['total_fees'];

// Získat dostupné filtry
$symbols = [];
$currencies = [];
$platforms = [];
$years = [];

if ($pdo && $currentUserId) {
  // Symboly
  $stmt = $pdo->prepare("SELECT DISTINCT id FROM broker_trans WHERE user_id = ? AND trans_type = 'Sell' AND (product_type = 'Stock' OR product_type = 'Crypto') ORDER BY id");
  $stmt->execute([$currentUserId]);
  $symbols = $stmt->fetchAll(PDO::FETCH_COLUMN);
  
  // Měny
  $stmt = $pdo->prepare("SELECT DISTINCT currency FROM broker_trans WHERE user_id = ? AND trans_type = 'Sell' AND (product_type = 'Stock' OR product_type = 'Crypto') ORDER BY currency");
  $stmt->execute([$currentUserId]);
  $currencies = $stmt->fetchAll(PDO::FETCH_COLUMN);
  
  // Platformy
  $stmt = $pdo->prepare("SELECT DISTINCT platform FROM broker_trans WHERE user_id = ? AND trans_type = 'Sell' AND (product_type = 'Stock' OR product_type = 'Crypto') ORDER BY platform");
  $stmt->execute([$currentUserId]);
  $platforms = $stmt->fetchAll(PDO::FETCH_COLUMN);
  
  // Roky
  $stmt = $pdo->prepare("SELECT DISTINCT YEAR(date) as year FROM broker_trans WHERE user_id = ? AND trans_type = 'Sell' AND (product_type = 'Stock' OR product_type = 'Crypto') ORDER BY year DESC");
  $stmt->execute([$currentUserId]);
  $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Realizované zisky a ztráty - Portfolio Tracker</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="css/broker-overrides.css">
  <link rel="stylesheet" href="css/filter-mobile.css">
  <style>
    /* Sales specific styles */
    .sales-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    
    .stat-card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
      border: 1px solid #e2e8f0;
    }
    
    .stat-label {
      color: #64748b;
      font-size: 0.875rem;
      font-weight: 500;
      margin-bottom: 8px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .stat-value {
      font-size: 1.5rem;
      font-weight: 700;
      color: #1e293b;
    }
    
    .stat-value.positive {
      color: #059669;
    }
    
    .stat-value.negative {
      color: #ef4444;
    }
    
    .stat-subtitle {
      font-size: 0.875rem;
      color: #64748b;
      margin-top: 4px;
    }
    
    .profit-row {
      background: #f0fdf4 !important;
    }
    
    .loss-row {
      background: #fef2f2 !important;
    }
    
    .tax-indicator {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      font-weight: bold;
    }
    
    .tax-pass {
      background: #d4edda;
      color: #059669;
    }
    
    .tax-fail {
      background: #f8d7da;
      color: #ef4444;
    }
    
    .holding-period {
      font-size: 0.75rem;
      color: #64748b;
      margin-top: 2px;
    }
    
    .w-tax { 
      min-width: 100px; 
      text-align: center;
    }
    
    .w-profit { 
      min-width: 140px; 
    }
    
    .progress-bar {
      width: 100%;
      height: 20px;
      background: #e2e8f0;
      border-radius: 10px;
      overflow: hidden;
      margin-top: 8px;
    }
    
    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, #ef4444, #059669);
      transition: width 0.3s ease;
    }
  </style>
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
      <a href="/index_menu.php" class="btn btn-secondary">Menu</a>
      <a href="../../php/logout.php" class="btn btn-danger">Odhlásit se</a>
    </div>
  </nav>
</header>

  <main class="main-content">
      </div>

    <?php if (!$pdo): ?>
      <div class="alert alert-danger">Nepodařilo se připojit k databázi. Zkontrolujte konfiguraci.</div>
    <?php elseif (!$currentUserId): ?>
      <div class="alert alert-warning">Uživatel není správně přihlášen. Obnovte stránku nebo se znovu přihlaste.</div>
    <?php else: ?>
      
      <!-- Statistics Cards -->
      <div class="sales-stats">
        <div class="stat-card">
          <div class="stat-label">Čistý zisk/ztráta</div>
          <div class="stat-value <?php echo $totalStats['total_net_profit'] >= 0 ? 'positive' : 'negative'; ?>">
            <?php echo number_format($totalStats['total_net_profit'], 2, ',', ' '); ?> Kč
          </div>
          <div class="stat-subtitle">Po odečtení poplatků</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Realizované zisky</div>
          <div class="stat-value positive">
            +<?php echo number_format($totalStats['total_profit_czk'], 2, ',', ' '); ?> Kč
          </div>
          <div class="stat-subtitle"><?php echo $totalStats['winning_trades']; ?> ziskových obchodů</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Realizované ztráty</div>
          <div class="stat-value negative">
            -<?php echo number_format($totalStats['total_loss_czk'], 2, ',', ' '); ?> Kč
          </div>
          <div class="stat-subtitle"><?php echo $totalStats['losing_trades']; ?> ztrátových obchodů</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Osvobozené od daně</div>
          <div class="stat-value positive">
            <?php echo number_format($totalStats['tax_free_profit'], 2, ',', ' '); ?> Kč
          </div>
          <div class="stat-subtitle">Drženo 3+ roky</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Zdanitelné</div>
          <div class="stat-value">
            <?php echo number_format($totalStats['taxable_profit'], 2, ',', ' '); ?> Kč
          </div>
          <div class="stat-subtitle">Drženo <3 roky</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Úspěšnost</div>
          <div class="stat-value">
            <?php 
              $winRate = $totalStats['total_sales'] > 0 ? 
                ($totalStats['winning_trades'] / $totalStats['total_sales']) * 100 : 0;
              echo number_format($winRate, 1, ',', ' '); 
            ?>%
          </div>
          <div class="progress-bar">
            <div class="progress-fill" style="width: <?php echo $winRate; ?>%"></div>
          </div>
        </div>
      </div>

      <!-- Filter Form -->
      <div class="content-box">
        <h2 class="collapsible-header">Filtrovat prodeje <span class="toggle-icon">▼</span></h2>
        <div class="collapsible-content">
        <form method="get" id="filterForm">
          <div class="filter-grid">
            <div class="form-group">
              <label for="symbol">Symbol/Ticker</label>
              <select id="symbol" name="symbol" class="input">
                <option value="">Vše</option>
                <?php foreach($symbols as $sym): ?>
                  <option value="<?php echo htmlspecialchars($sym); ?>" 
                          <?php echo $symbol === $sym ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($sym); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="currency">Měna</label>
              <select id="currency" name="currency" class="input">
                <option value="">Vše</option>
                <?php foreach($currencies as $curr): ?>
                  <option value="<?php echo htmlspecialchars($curr); ?>" 
                          <?php echo $currency === $curr ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($curr); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="platform">Platforma</label>
              <select id="platform" name="platform" class="input">
                <option value="">Vše</option>
                <?php foreach($platforms as $plat): ?>
                  <option value="<?php echo htmlspecialchars($plat); ?>" 
                          <?php echo $platform === $plat ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($plat); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="year">Rok</label>
              <select id="year" name="year" class="input">
                <option value="">Vše</option>
                <?php foreach($years as $yr): ?>
                  <option value="<?php echo $yr; ?>" <?php echo $yearFilter === $yr ? 'selected' : ''; ?>>
                    <?php echo $yr; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="tax_test">Daňový test</label>
              <select id="tax_test" name="tax_test" class="input">
                <option value="">Vše</option>
                <option value="yes" <?php echo $taxTestFilter === 'yes' ? 'selected' : ''; ?>>✓ Prošlo (3+ roky)</option>
                <option value="no" <?php echo $taxTestFilter === 'no' ? 'selected' : ''; ?>>✗ Neprošlo (<3 roky)</option>
              </select>
            </div>

            <div class="form-group">
              <label for="date_from">Datum od</label>
              <input type="date" id="date_from" name="date_from" class="input" 
                     value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>

            <div class="form-group">
              <label for="date_to">Datum do</label>
              <input type="date" id="date_to" name="date_to" class="input" 
                     value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>

            <div class="form-group">
              <label for="cons">Agregace</label>
              <select id="cons" name="cons" class="input">
                <option value="none" <?php echo $groupMode === 'none' ? 'selected' : ''; ?>>Detailní</option>
                <option value="ticker" <?php echo $groupMode === 'ticker' ? 'selected' : ''; ?>>Podle tickeru</option>
                <option value="currency" <?php echo $groupMode === 'currency' ? 'selected' : ''; ?>>Podle měny</option>
                <option value="platform" <?php echo $groupMode === 'platform' ? 'selected' : ''; ?>>Podle platformy</option>
                <option value="year" <?php echo $groupMode === 'year' ? 'selected' : ''; ?>>Podle roku</option>
                <option value="month" <?php echo $groupMode === 'month' ? 'selected' : ''; ?>>Podle měsíce</option>
                <option value="tax_status" <?php echo $groupMode === 'tax_status' ? 'selected' : ''; ?>>Podle daňového statusu</option>
                <option value="all" <?php echo $groupMode === 'all' ? 'selected' : ''; ?>>Celkem</option>
              </select>
            </div>

            <div class="form-group" style="display: flex; align-items: flex-end; gap: 10px;">
              <button type="submit" class="btn btn-primary">Filtrovat</button>
              <a href="sal.php" class="btn btn-light">Reset</a>
            </div>
          </div>
        </form>
        </div>
      </div>

      <!-- Data Table -->
      <div class="content-box">
        <h2>
          <?php if ($grouped): ?>
            Agregované prodeje
            <?php if($groupMode === 'ticker'): ?> podle tickeru
            <?php elseif($groupMode === 'currency'): ?> podle měny
            <?php elseif($groupMode === 'platform'): ?> podle platformy
            <?php elseif($groupMode === 'year'): ?> podle roku
            <?php elseif($groupMode === 'month'): ?> podle měsíce
            <?php elseif($groupMode === 'tax_status'): ?> podle daňového statusu
            <?php endif; ?>
          <?php else: ?>
            Detail prodejů
          <?php endif; ?>
          <small style="color: #64748b; font-weight: normal;">
            (<?php echo $grouped ? count($rows_grouped) : count($salesAnalysis); ?> záznamů)
          </small>
        </h2>
        
        <div class="table-container">
          <?php if ($grouped): ?>
          <table class="table" id="txTable">
            <thead>
              <tr>
                <th data-key="label" data-type="text"><span class="th-sort">
                  <?php 
                    if($groupMode === 'ticker') echo 'Ticker';
                    elseif($groupMode === 'currency') echo 'Měna';
                    elseif($groupMode === 'platform') echo 'Platforma';
                    elseif($groupMode === 'year') echo 'Rok';
                    elseif($groupMode === 'month') echo 'Měsíc';
                    elseif($groupMode === 'tax_status') echo 'Daňový status';
                    else echo 'Skupina';
                  ?>
                  <span class="dir"></span></span>
                </th>
                <th class="w-count" data-key="trades_count" data-type="number"><span class="th-sort">Počet obchodů <span class="dir"></span></span></th>
                <th class="w-count" data-key="winning_trades" data-type="number"><span class="th-sort">Ziskové <span class="dir"></span></span></th>
                <th class="w-count" data-key="losing_trades" data-type="number"><span class="th-sort">Ztrátové <span class="dir"></span></span></th>
                <th class="w-amczk" data-key="total_sold_czk" data-type="number"><span class="th-sort">Prodáno za CZK <span class="dir"></span></span></th>
                <th class="w-amczk" data-key="total_bought_czk" data-type="number"><span class="th-sort">Koupeno za CZK <span class="dir"></span></span></th>
                <th class="w-profit" data-key="profit_czk" data-type="number"><span class="th-sort">Zisky CZK <span class="dir"></span></span></th>
                <th class="w-profit" data-key="loss_czk" data-type="number"><span class="th-sort">Ztráty CZK <span class="dir"></span></span></th>
                <th class="w-fee" data-key="fees" data-type="number"><span class="th-sort">Poplatky <span class="dir"></span></span></th>
                <th class="w-profit" data-key="net_profit_czk" data-type="number"><span class="th-sort">Čistý zisk CZK <span class="dir"></span></span></th>
                <th class="w-count" data-key="tax_free_trades" data-type="number"><span class="th-sort">Osvobozené <span class="dir"></span></span></th>
                <th class="w-profit" data-key="tax_free_profit" data-type="number"><span class="th-sort">Osvob. zisk <span class="dir"></span></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows_grouped as $g): ?>
                <tr
                  data-label="<?php echo htmlspecialchars($g['label']); ?>"
                  data-trades_count="<?php echo (int)$g['trades_count']; ?>"
                  data-winning_trades="<?php echo (int)$g['winning_trades']; ?>"
                  data-losing_trades="<?php echo (int)$g['losing_trades']; ?>"
                  data-total_sold_czk="<?php echo (float)$g['total_sold_czk']; ?>"
                  data-total_bought_czk="<?php echo (float)$g['total_bought_czk']; ?>"
                  data-profit_czk="<?php echo (float)$g['profit_czk']; ?>"
                  data-loss_czk="<?php echo (float)$g['loss_czk']; ?>"
                  data-fees="<?php echo (float)$g['fees']; ?>"
                  data-net_profit_czk="<?php echo (float)$g['net_profit_czk']; ?>"
                  data-tax_free_trades="<?php echo (int)$g['tax_free_trades']; ?>"
                  data-tax_free_profit="<?php echo (float)$g['tax_free_profit']; ?>"
                >
                  <td><?php echo htmlspecialchars($g['label']); ?></td>
                  <td class="num"><?php echo (int)$g['trades_count']; ?></td>
                  <td class="num" style="color: #059669;"><?php echo (int)$g['winning_trades']; ?></td>
                  <td class="num" style="color: #ef4444;"><?php echo (int)$g['losing_trades']; ?></td>
                  <td class="num"><?php echo number_format((float)$g['total_sold_czk'], 2, ',', ' '); ?></td>
                  <td class="num"><?php echo number_format((float)$g['total_bought_czk'], 2, ',', ' '); ?></td>
                  <td class="num" style="color: #059669;">+<?php echo number_format((float)$g['profit_czk'], 2, ',', ' '); ?></td>
                  <td class="num" style="color: #ef4444;">-<?php echo number_format((float)$g['loss_czk'], 2, ',', ' '); ?></td>
                  <td class="num"><?php echo number_format((float)$g['fees'], 2, ',', ' '); ?></td>
                  <td class="num" style="font-weight: bold; color: <?php echo $g['net_profit_czk'] >= 0 ? '#059669' : '#ef4444'; ?>">
                    <?php echo number_format((float)$g['net_profit_czk'], 2, ',', ' '); ?>
                  </td>
                  <td class="num"><?php echo (int)$g['tax_free_trades']; ?></td>
                  <td class="num"><?php echo number_format((float)$g['tax_free_profit'], 2, ',', ' '); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <table class="table" id="txTable">
            <thead>
              <tr>
                <th class="w-date" data-key="date" data-type="date"><span class="th-sort">Datum prodeje <span class="dir"></span></span></th>
                <th data-key="id" data-type="text"><span class="th-sort">Ticker <span class="dir"></span></span></th>
                <th class="w-qty" data-key="sell_qty" data-type="number"><span class="th-sort">Množství <span class="dir"></span></span></th>
                <th class="w-price" data-key="sell_price_czk" data-type="number"><span class="th-sort">Prodejní cena/ks <span class="dir"></span></span></th>
                <th class="w-price" data-key="avg_buy_price_czk" data-type="number"><span class="th-sort">Nákupní cena/ks <span class="dir"></span></span></th>
                <th class="w-profit" data-key="profit_czk" data-type="number"><span class="th-sort">Hrubý zisk CZK <span class="dir"></span></span></th>
                <th class="w-fee" data-key="fees" data-type="number"><span class="th-sort">Poplatky <span class="dir"></span></span></th>
                <th class="w-profit" data-key="net_profit_czk" data-type="number"><span class="th-sort">Čistý zisk CZK <span class="dir"></span></span></th>
                <th class="w-tax" data-key="tax_test_passed" data-type="number"><span class="th-sort">Daňový test <span class="dir"></span></span></th>
                <th class="w-count" data-key="holding_days" data-type="number"><span class="th-sort">Drženo dní <span class="dir"></span></span></th>
                <th data-key="platform" data-type="text"><span class="th-sort">Platforma <span class="dir"></span></span></th>
                <th data-key="currency" data-type="text"><span class="th-sort">Měna <span class="dir"></span></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($salesAnalysis as $s): ?>
              <?php 
                $rowClass = $s['profit_czk'] >= 0 ? 'profit-row' : 'loss-row';
              ?>
              <tr class="<?php echo $rowClass; ?>"
                data-date="<?php echo htmlspecialchars($s['date']); ?>"
                data-id="<?php echo htmlspecialchars($s['id']); ?>"
                data-sell_qty="<?php echo (float)$s['sell_qty']; ?>"
                data-sell_price_czk="<?php echo (float)$s['sell_price_czk']; ?>"
                data-avg_buy_price_czk="<?php echo (float)$s['avg_buy_price_czk']; ?>"
                data-profit_czk="<?php echo (float)$s['profit_czk']; ?>"
                data-fees="<?php echo (float)$s['fees']; ?>"
                data-net_profit_czk="<?php echo (float)$s['net_profit_czk']; ?>"
                data-tax_test_passed="<?php echo $s['tax_test_passed'] ? 1 : 0; ?>"
                data-holding_days="<?php echo (int)$s['holding_days']; ?>"
                data-platform="<?php echo htmlspecialchars($s['platform']); ?>"
                data-currency="<?php echo htmlspecialchars($s['currency']); ?>"
              >
                <td><?php echo htmlspecialchars($s['date']); ?></td>
                <td><strong><?php echo htmlspecialchars($s['id']); ?></strong></td>
                <td class="num"><?php echo number_format((float)$s['sell_qty'], 2, ',', ' '); ?></td>
                <td class="num"><?php echo number_format((float)$s['sell_price_czk'], 2, ',', ' '); ?></td>
                <td class="num"><?php echo number_format((float)$s['avg_buy_price_czk'], 2, ',', ' '); ?></td>
                <td class="num" style="color: <?php echo $s['profit_czk'] >= 0 ? '#059669' : '#ef4444'; ?>">
                  <?php echo number_format((float)$s['profit_czk'], 2, ',', ' '); ?>
                  <small>(<?php echo number_format($s['profit_percent'], 1, ',', ' '); ?>%)</small>
                </td>
                <td class="num"><?php echo number_format((float)$s['fees'], 2, ',', ' '); ?></td>
                <td class="num" style="font-weight: bold; color: <?php echo $s['net_profit_czk'] >= 0 ? '#059669' : '#ef4444'; ?>">
                  <?php echo number_format((float)$s['net_profit_czk'], 2, ',', ' '); ?>
                </td>
                <td class="w-tax">
                  <?php if($s['tax_test_passed']): ?>
                    <span class="tax-indicator tax-pass" title="Osvobozeno od daně (drženo 3+ roky)">✓</span>
                  <?php else: ?>
                    <span class="tax-indicator tax-fail" title="Zdanitelné (drženo méně než 3 roky)">✗</span>
                  <?php endif; ?>
                </td>
                <td class="num">
                  <?php echo number_format($s['holding_days'], 0, ',', ' '); ?>
                  <div class="holding-period">
                    <?php 
                      $years = floor($s['holding_days'] / 365);
                      $months = floor(($s['holding_days'] % 365) / 30);
                      if ($years > 0) {
                        echo $years . 'r ' . $months . 'm';
                      } else {
                        echo $months . ' měs.';
                      }
                    ?>
                  </div>
                </td>
                <td><?php echo htmlspecialchars($s['platform']); ?></td>
                <td><?php echo htmlspecialchars($s['currency']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </main>

<script>
/* Sorting for both normal and grouped tables*/
(function(){
  const table = document.getElementById('txTable');
  if(!table) return;
  const thead = table.querySelector('thead');
  const tbody = table.querySelector('tbody');
  let currentKey = null, currentDir = 1;
  function getVal(tr,key,type){
    const raw = tr.dataset[key];
    if(type==='number'){ const n=parseFloat(raw); return isNaN(n)?0:n; }
    if(type==='date'){ const t=Date.parse(raw); return isNaN(t)?0:t; }
    return (raw||'').toString().toLowerCase();
  }
  function setIndicator(th,dir){
    thead.querySelectorAll('th .dir').forEach(s=>s.textContent='');
    const span = th.querySelector('.dir'); if(span) span.textContent = dir===1?'▲':'▼';
  }
  thead.addEventListener('click', (e)=>{
    const th = e.target.closest('th'); if(!th) return;
    const key = th.dataset.key, type = th.dataset.type || 'text'; if(!key) return;
    if(currentKey===key) currentDir*=-1; else { currentKey=key; currentDir=1; }
    setIndicator(th,currentDir);
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a,b)=>{
      const va=getVal(a,key,type), vb=getVal(b,key,type);
      if(va<vb) return -1*currentDir; if(va>vb) return 1*currentDir; return 0;
    });
    rows.forEach(r=>tbody.appendChild(r));
  });
})();

/* Auto-submit on filter change */
(function(){
  const form = document.getElementById('filterForm');
  if(!form) return;
  const selects = form.querySelectorAll('select:not(#cons)');
  selects.forEach(select => {
    select.addEventListener('change', () => {
      form.submit();
    });
  });
})();
</script>

<script src="js/table-scroll.js"></script>
<script src="js/collapsible.js"></script>
</body>
</html>