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
$currentPage = 'div';

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

/* ===== Helpers ===== */
function getLookupWithCounts($pdo,$userId,$col){
  $allowed=['id','currency','platform','product_type','trans_type'];
  if(!in_array($col,$allowed,true)) return [];
  // Pro dividendy filtrujeme pouze trans_type='Dividend' nebo 'Withholding' (daň z dividend)
  $sql="SELECT $col as val, COUNT(*) as c 
        FROM broker_trans 
        WHERE user_id=? AND $col IS NOT NULL AND $col<>'' 
        AND (trans_type='Dividend' OR trans_type='Withholding')
        GROUP BY $col ORDER BY $col";
  $st=$pdo->prepare($sql); $st->execute([$userId]); return $st->fetchAll();
}

/* ===== Filters ===== */
$symbol     = trim($_GET['symbol'] ?? '');
$currency   = trim($_GET['currency'] ?? '');
$platform   = trim($_GET['platform'] ?? '');
$dateFrom   = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo     = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$groupMode  = isset($_GET['cons']) ? $_GET['cons'] : 'none';
$grouped    = ($groupMode !== 'none');
$lookup     = isset($_GET['lookup']) ? trim($_GET['lookup']) : '';

// Pro dividendy specifické
$showTax    = isset($_GET['show_tax']) ? $_GET['show_tax'] : 'yes';
$yearFilter = isset($_GET['year']) ? trim($_GET['year']) : '';

/* ===== Data - pouze dividendy a srážkové daně ===== */
$rows=[];
$taxRows=[];
if ($pdo && $currentUserId) {
  // Hlavní dotaz pro dividendy
  $sql="SELECT trans_id,date,id,amount,price,ex_rate,amount_cur,currency,amount_czk,platform,product_type,trans_type,fees,notes
        FROM broker_trans WHERE user_id = ? AND trans_type='Dividend'";
  $params = [$currentUserId];
  
  if($symbol!==''){ $sql.=" AND id = ?"; $params[]=$symbol; }
  if($currency!==''){ $sql.=" AND currency = ?"; $params[]=$currency; }
  if($platform!==''){ $sql.=" AND platform = ?"; $params[]=$platform; }
  if($dateFrom!==''){ $sql.=" AND date >= ?"; $params[]=$dateFrom; }
  if($dateTo!==''){ $sql.=" AND date <= ?"; $params[]=$dateTo; }
  if($yearFilter!==''){ $sql.=" AND YEAR(date) = ?"; $params[]=$yearFilter; }
  
  $sql.=" ORDER BY date DESC, trans_id DESC LIMIT 2000";
  $stmt=$pdo->prepare($sql); $stmt->execute($params);
  $rows=$stmt->fetchAll();
  
  // Dotaz pro srážkové daně
  if ($showTax === 'yes') {
    $sqlTax="SELECT trans_id,date,id,amount,price,ex_rate,amount_cur,currency,amount_czk,platform,product_type,trans_type,fees,notes
            FROM broker_trans WHERE user_id = ? AND trans_type='Withholding'";
    $paramsTax = [$currentUserId];
    
    if($symbol!==''){ $sqlTax.=" AND id = ?"; $paramsTax[]=$symbol; }
    if($currency!==''){ $sqlTax.=" AND currency = ?"; $paramsTax[]=$currency; }
    if($platform!==''){ $sqlTax.=" AND platform = ?"; $paramsTax[]=$platform; }
    if($dateFrom!==''){ $sqlTax.=" AND date >= ?"; $paramsTax[]=$dateFrom; }
    if($dateTo!==''){ $sqlTax.=" AND date <= ?"; $paramsTax[]=$dateTo; }
    if($yearFilter!==''){ $sqlTax.=" AND YEAR(date) = ?"; $paramsTax[]=$yearFilter; }
    
    $sqlTax.=" ORDER BY date DESC, trans_id DESC LIMIT 2000";
    $stmtTax=$pdo->prepare($sqlTax); $stmtTax->execute($paramsTax);
    $taxRows=$stmtTax->fetchAll();
  }
}

/* ===== Lookups - pouze pro dividendy ===== */
$ids = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'id') : [];
$currencies = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'currency') : [];
$platforms  = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'platform') : [];

// Získáme roky kdy byly dividendy
$years = [];
if ($pdo && $currentUserId) {
  $sqlYears = "SELECT DISTINCT YEAR(date) as year 
               FROM broker_trans 
               WHERE user_id=? AND (trans_type='Dividend' OR trans_type='Withholding')
               ORDER BY year DESC";
  $stYears = $pdo->prepare($sqlYears);
  $stYears->execute([$currentUserId]);
  $years = $stYears->fetchAll(PDO::FETCH_COLUMN);
}

/* ===== Aggregation and Stats ===== */
$totalStats = [
  'total_dividends_czk' => 0,
  'total_tax_czk' => 0,
  'total_net_czk' => 0,
  'total_dividends_cur' => [],
  'total_tax_cur' => []
];

// Calculate totals globally (regardless of grouping)
if (!empty($rows)) {
    foreach ($rows as $r) {
        $totalStats['total_dividends_czk'] += (float)$r['amount_czk'];
        if (!isset($totalStats['total_dividends_cur'][$r['currency']])) {
            $totalStats['total_dividends_cur'][$r['currency']] = 0;
        }
        $totalStats['total_dividends_cur'][$r['currency']] += (float)$r['amount_cur'];
    }
}

if (!empty($taxRows)) {
    foreach ($taxRows as $r) {
        $totalStats['total_tax_czk'] += abs((float)$r['amount_czk']);
        if (!isset($totalStats['total_tax_cur'][$r['currency']])) {
            $totalStats['total_tax_cur'][$r['currency']] = 0;
        }
        $totalStats['total_tax_cur'][$r['currency']] += abs((float)$r['amount_cur']);
    }
}
$totalStats['total_net_czk'] = $totalStats['total_dividends_czk'] - $totalStats['total_tax_czk'];

$rows_grouped = [];
if ($grouped && (!empty($rows) || !empty($taxRows))) {
  $groups = [];
  
  // Zpracování dividend
  foreach ($rows as $r) {
    if ($groupMode === 'item') {
      $key = $r['id'];
      $label = $r['id'];
    } elseif ($groupMode === 'currency') {
      $key = $r['currency'];
      $label = $r['currency'];
    } elseif ($groupMode === 'platform') {
      $key = $r['platform'];
      $label = $r['platform'];
    } elseif ($groupMode === 'year') {
      $key = date('Y', strtotime($r['date']));
      $label = 'Rok ' . $key;
    } elseif ($groupMode === 'month') {
      $key = date('Y-m', strtotime($r['date']));
      $label = date('m/Y', strtotime($r['date']));
    } else { /* all */
      $key = '__ALL__';
      $label = 'Celkem';
    }

    if (!isset($groups[$key])) {
      $groups[$key] = [
        'label' => $label,
        'id' => ($groupMode === 'item') ? $r['id'] : '-',
        'currency' => ($groupMode === 'item' || $groupMode === 'currency') ? $r['currency'] : 'MIX',
        'platform' => ($groupMode === 'platform') ? $r['platform'] : 'MIX',
        'div_count' => 0,
        'div_czk' => 0.0,
        'div_cur' => 0.0,
        'tax_czk' => 0.0,
        'tax_cur' => 0.0,
        'net_czk' => 0.0,
        'net_cur' => 0.0,
        'last_date' => $r['date'],
        'first_date' => $r['date']
      ];
    }
    
    $g =& $groups[$key];
    $g['div_count']++;
    $g['div_czk'] += (float)$r['amount_czk'];
    $g['div_cur'] += (float)$r['amount_cur'];
    
    // Aktualizace dat
    if ($r['date'] > $g['last_date']) $g['last_date'] = $r['date'];
    if ($r['date'] < $g['first_date']) $g['first_date'] = $r['date'];
    
    unset($g);
  }
  
  // Zpracování srážkových daní
  foreach ($taxRows as $r) {
    if ($groupMode === 'item') {
      $key = $r['id'];
    } elseif ($groupMode === 'currency') {
      $key = $r['currency'];
    } elseif ($groupMode === 'platform') {
      $key = $r['platform'];
    } elseif ($groupMode === 'year') {
      $key = date('Y', strtotime($r['date']));
    } elseif ($groupMode === 'month') {
      $key = date('Y-m', strtotime($r['date']));
    } else {
      $key = '__ALL__';
    }
    
    if (isset($groups[$key])) {
      $groups[$key]['tax_czk'] += abs((float)$r['amount_czk']);
      $groups[$key]['tax_cur'] += abs((float)$r['amount_cur']);
    }
  }
  
  // Výpočet čistých dividend
  foreach ($groups as &$g) {
    $g['net_czk'] = $g['div_czk'] - $g['tax_czk'];
    $g['net_cur'] = $g['div_cur'] - $g['tax_cur'];
  }
  
  $rows_grouped = array_values($groups);
}

// Sloučení dividend a daní pro detailní zobrazení
if (!$grouped && $showTax === 'yes') {
  $allRows = array_merge($rows, $taxRows);
  usort($allRows, function($a, $b) {
    $dateComp = strcmp($b['date'], $a['date']); // DESC
    if ($dateComp === 0) {
      return $b['trans_id'] - $a['trans_id']; // DESC
    }
    return $dateComp;
  });
  $rows = $allRows;
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Přehled dividend - Portfolio Tracker</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="css/broker-overrides.css">
  <style>
    /* Dividend specific styles */
    .dividend-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
    
    .dividend-row {
      background: #f0fdf4 !important;
    }
    
    .tax-row {
      background: #fef2f2 !important;
    }
    
    .year-selector {
      min-width: 120px;
    }
    
    .currency-breakdown {
      margin-top: 8px;
      padding-top: 8px;
      border-top: 1px solid #e2e8f0;
      font-size: 0.875rem;
      color: #64748b;
    }
    
    .currency-item {
      display: flex;
      justify-content: space-between;
      margin-top: 4px;
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
      <?php if (!empty($rows) || !empty($taxRows)): ?>
      <div class="dividend-stats">
        <div class="stat-card">
          <div class="stat-label">Dividendy celkem</div>
          <div class="stat-value positive">
            <?php echo number_format($totalStats['total_dividends_czk'], 2, ',', ' '); ?> Kč
          </div>
          <?php if (!empty($totalStats['total_dividends_cur'])): ?>
          <div class="currency-breakdown">
            <?php foreach ($totalStats['total_dividends_cur'] as $cur => $amount): ?>
            <div class="currency-item">
              <span><?php echo htmlspecialchars($cur); ?>:</span>
              <span><?php echo number_format($amount, 2, ',', ' '); ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Srážková daň</div>
          <div class="stat-value negative">
            -<?php echo number_format($totalStats['total_tax_czk'], 2, ',', ' '); ?> Kč
          </div>
          <?php if (!empty($totalStats['total_tax_cur'])): ?>
          <div class="currency-breakdown">
            <?php foreach ($totalStats['total_tax_cur'] as $cur => $amount): ?>
            <div class="currency-item">
              <span><?php echo htmlspecialchars($cur); ?>:</span>
              <span>-<?php echo number_format($amount, 2, ',', ' '); ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Čisté dividendy</div>
          <div class="stat-value">
            <?php echo number_format($totalStats['total_net_czk'], 2, ',', ' '); ?> Kč
          </div>
        </div>
        
        <div class="stat-card">
          <div class="stat-label">Počet výplat</div>
          <div class="stat-value">
            <?php echo count($rows); ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Filter Form -->
      <div class="content-box">
        <h2>Filtrovat dividendy</h2>
        <form method="get" id="filterForm">
          <div class="filter-grid">
            <div class="form-group">
              <label for="lookup">Rychlé hledání</label>
              <input type="text" id="lookup" name="lookup" class="input" 
                     placeholder="Hledat..." value="<?php echo htmlspecialchars($lookup); ?>">
            </div>
            
            <div class="form-group">
              <label for="symbol">Symbol/Ticker</label>
              <select id="symbol" name="symbol" class="input">
                <option value="">Vše</option>
                <?php foreach($ids as $item): ?>
                  <option value="<?php echo htmlspecialchars($item['val']); ?>" 
                          <?php echo $symbol === $item['val'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['val']); ?> (<?php echo $item['c']; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="currency">Měna</label>
              <select id="currency" name="currency" class="input">
                <option value="">Vše</option>
                <?php foreach($currencies as $item): ?>
                  <option value="<?php echo htmlspecialchars($item['val']); ?>" 
                          <?php echo $currency === $item['val'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['val']); ?> (<?php echo $item['c']; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="platform">Platforma</label>
              <select id="platform" name="platform" class="input">
                <option value="">Vše</option>
                <?php foreach($platforms as $item): ?>
                  <option value="<?php echo htmlspecialchars($item['val']); ?>" 
                          <?php echo $platform === $item['val'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['val']); ?> (<?php echo $item['c']; ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="year">Rok</label>
              <select id="year" name="year" class="input year-selector">
                <option value="">Vše</option>
                <?php foreach($years as $yr): ?>
                  <option value="<?php echo $yr; ?>" <?php echo $yearFilter === $yr ? 'selected' : ''; ?>>
                    <?php echo $yr; ?>
                  </option>
                <?php endforeach; ?>
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
                <option value="item" <?php echo $groupMode === 'item' ? 'selected' : ''; ?>>Podle tickeru</option>
                <option value="currency" <?php echo $groupMode === 'currency' ? 'selected' : ''; ?>>Podle měny</option>
                <option value="platform" <?php echo $groupMode === 'platform' ? 'selected' : ''; ?>>Podle platformy</option>
                <option value="year" <?php echo $groupMode === 'year' ? 'selected' : ''; ?>>Podle roku</option>
                <option value="month" <?php echo $groupMode === 'month' ? 'selected' : ''; ?>>Podle měsíce</option>
                <option value="all" <?php echo $groupMode === 'all' ? 'selected' : ''; ?>>Celkem</option>
              </select>
            </div>

            <div class="form-group">
              <label for="show_tax">Zobrazit daně</label>
              <select id="show_tax" name="show_tax" class="input">
                <option value="yes" <?php echo $showTax === 'yes' ? 'selected' : ''; ?>>Ano</option>
                <option value="no" <?php echo $showTax === 'no' ? 'selected' : ''; ?>>Ne</option>
              </select>
            </div>

            <div class="form-group" style="display: flex; align-items: flex-end; gap: 10px;">
              <button type="submit" class="btn btn-primary">Filtrovat</button>
              <a href="div.php" class="btn btn-light">Reset</a>
            </div>
          </div>
        </form>
      </div>

      <!-- Data Table -->
      <div class="content-box">
        <h2>
          <?php if ($grouped): ?>
            Agregované dividendy
            <?php if($groupMode === 'item'): ?> podle tickeru
            <?php elseif($groupMode === 'currency'): ?> podle měny
            <?php elseif($groupMode === 'platform'): ?> podle platformy
            <?php elseif($groupMode === 'year'): ?> podle roku
            <?php elseif($groupMode === 'month'): ?> podle měsíce
            <?php endif; ?>
          <?php else: ?>
            Seznam dividend
          <?php endif; ?>
          <small style="color: #64748b; font-weight: normal;">
            (<?php echo $grouped ? count($rows_grouped) : count($rows); ?> záznamů)
          </small>
        </h2>
        
        <div class="table-container">
          <?php if ($grouped): ?>
          <table class="table" id="txTable">
            <thead>
              <tr>
                <th data-key="label" data-type="text"><span class="th-sort">
                  <?php 
                    if($groupMode === 'item') echo 'Ticker';
                    elseif($groupMode === 'currency') echo 'Měna';
                    elseif($groupMode === 'platform') echo 'Platforma';
                    elseif($groupMode === 'year') echo 'Rok';
                    elseif($groupMode === 'month') echo 'Měsíc';
                    else echo 'Skupina';
                  ?>
                  <span class="dir"></span></span>
                </th>
                <?php if($groupMode === 'item'): ?>
                <th data-key="currency" data-type="text"><span class="th-sort">Měna <span class="dir"></span></span></th>
                <th data-key="platform" data-type="text"><span class="th-sort">Platforma <span class="dir"></span></span></th>
                <?php endif; ?>
                <th class="w-count" data-key="div_count" data-type="number"><span class="th-sort">Počet výplat <span class="dir"></span></span></th>
                <th class="w-amczk" data-key="div_czk" data-type="number"><span class="th-sort">Dividendy CZK <span class="dir"></span></span></th>
                <th class="w-amcur" data-key="div_cur" data-type="number"><span class="th-sort">Dividendy (měna) <span class="dir"></span></span></th>
                <th class="w-amczk" data-key="tax_czk" data-type="number"><span class="th-sort">Daň CZK <span class="dir"></span></span></th>
                <th class="w-amcur" data-key="tax_cur" data-type="number"><span class="th-sort">Daň (měna) <span class="dir"></span></span></th>
                <th class="w-amczk" data-key="net_czk" data-type="number"><span class="th-sort">Čistá dividenda CZK <span class="dir"></span></span></th>
                <th class="w-date" data-key="first_date" data-type="date"><span class="th-sort">První výplata <span class="dir"></span></span></th>
                <th class="w-date" data-key="last_date" data-type="date"><span class="th-sort">Poslední výplata <span class="dir"></span></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows_grouped as $g): ?>
                <tr
                  data-label="<?php echo htmlspecialchars($g['label']); ?>"
                  data-id="<?php echo htmlspecialchars($g['id']); ?>"
                  data-currency="<?php echo htmlspecialchars($g['currency']); ?>"
                  data-platform="<?php echo htmlspecialchars($g['platform']); ?>"
                  data-div_count="<?php echo (int)$g['div_count']; ?>"
                  data-div_czk="<?php echo (float)$g['div_czk']; ?>"
                  data-div_cur="<?php echo (float)$g['div_cur']; ?>"
                  data-tax_czk="<?php echo (float)$g['tax_czk']; ?>"
                  data-tax_cur="<?php echo (float)$g['tax_cur']; ?>"
                  data-net_czk="<?php echo (float)$g['net_czk']; ?>"
                  data-first_date="<?php echo htmlspecialchars($g['first_date']); ?>"
                  data-last_date="<?php echo htmlspecialchars($g['last_date']); ?>"
                >
                  <td><?php echo htmlspecialchars($g['label']); ?></td>
                  <?php if($groupMode === 'item'): ?>
                  <td><?php echo htmlspecialchars($g['currency']); ?></td>
                  <td><?php echo htmlspecialchars($g['platform']); ?></td>
                  <?php endif; ?>
                  <td class="num"><?php echo (int)$g['div_count']; ?></td>
                  <td class="num"><?php echo number_format((float)$g['div_czk'], 2, ',', ' '); ?></td>
                  <td class="num"><?php echo number_format((float)$g['div_cur'], 2, ',', ' '); ?></td>
                  <td class="num"><?php echo number_format((float)$g['tax_czk'], 2, ',', ' '); ?></td>
                  <td class="num"><?php echo number_format((float)$g['tax_cur'], 2, ',', ' '); ?></td>
                  <td class="num"><strong><?php echo number_format((float)$g['net_czk'], 2, ',', ' '); ?></strong></td>
                  <td><?php echo htmlspecialchars($g['first_date']); ?></td>
                  <td><?php echo htmlspecialchars($g['last_date']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <table class="table" id="txTable">
            <thead>
              <tr>
                <th class="w-date" data-key="date" data-type="date"><span class="th-sort">Datum <span class="dir"></span></span></th>
                <th data-key="id" data-type="text"><span class="th-sort">Ticker <span class="dir"></span></span></th>
                <th data-key="trans_type" data-type="text"><span class="th-sort">Typ <span class="dir"></span></span></th>
                <th class="w-amcur" data-key="amount_cur" data-type="number"><span class="th-sort">Částka (měna) <span class="dir"></span></span></th>
                <th data-key="currency" data-type="text"><span class="th-sort">Měna <span class="dir"></span></span></th>
                <th class="w-exrate" data-key="ex_rate" data-type="number"><span class="th-sort">Kurz <span class="dir"></span></span></th>
                <th class="w-amczk" data-key="amount_czk" data-type="number"><span class="th-sort">Částka CZK <span class="dir"></span></span></th>
                <th data-key="platform" data-type="text"><span class="th-sort">Platforma <span class="dir"></span></span></th>
                <th data-key="notes" data-type="text"><span class="th-sort">Poznámka <span class="dir"></span></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($rows as $r): ?>
              <?php 
                $rowClass = '';
                if ($r['trans_type'] === 'Dividend') {
                  $rowClass = 'dividend-row';
                } elseif ($r['trans_type'] === 'Withholding') {
                  $rowClass = 'tax-row';
                }
              ?>
              <tr class="<?php echo $rowClass; ?>"
                data-date="<?php echo htmlspecialchars($r['date']); ?>"
                data-id="<?php echo htmlspecialchars($r['id']); ?>"
                data-trans_type="<?php echo htmlspecialchars($r['trans_type']); ?>"
                data-amount_cur="<?php echo (float)$r['amount_cur']; ?>"
                data-currency="<?php echo htmlspecialchars($r['currency']); ?>"
                data-ex_rate="<?php echo (float)$r['ex_rate']; ?>"
                data-amount_czk="<?php echo (float)$r['amount_czk']; ?>"
                data-platform="<?php echo htmlspecialchars($r['platform']); ?>"
                data-notes="<?php echo htmlspecialchars($r['notes']); ?>"
              >
                <td><?php echo htmlspecialchars($r['date']); ?></td>
                <td><?php echo htmlspecialchars($r['id']); ?></td>
                <td>
                  <?php if($r['trans_type'] === 'Dividend'): ?>
                    <span style="color: #059669;">Dividenda</span>
                  <?php elseif($r['trans_type'] === 'Withholding'): ?>
                    <span style="color: #ef4444;">Srážková daň</span>
                  <?php else: ?>
                    <?php echo htmlspecialchars($r['trans_type']); ?>
                  <?php endif; ?>
                </td>
                <td class="num"><?php echo number_format((float)$r['amount_cur'], 2, ',', ' '); ?></td>
                <td><?php echo htmlspecialchars($r['currency']); ?></td>
                <td class="num"><?php echo number_format((float)$r['ex_rate'], 4, ',', ' '); ?></td>
                <td class="num"><?php echo number_format((float)$r['amount_czk'], 2, ',', ' '); ?></td>
                <td><?php echo htmlspecialchars($r['platform']); ?></td>
                <td><?php echo htmlspecialchars($r['notes']); ?></td>
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

/* Live search filtering */
(function(){
  const form = document.getElementById('filterForm');
  const lookup = document.getElementById('lookup');
  if(!form || !lookup) return;
  let t = null;
  function submitNow(){
    form.requestSubmit ? form.requestSubmit() : form.submit();
  }
  function schedule(){
    if(t) clearTimeout(t);
    t = setTimeout(()=>{
      submitNow();
    }, 350);
  }
  lookup.addEventListener('input', schedule);
  lookup.addEventListener('change', schedule);
  lookup.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); submitNow(); } });
})();

/* Auto-submit on filter change */
(function(){
  const form = document.getElementById('filterForm');
  if(!form) return;
  const selects = form.querySelectorAll('select:not(#cons):not(#show_tax)');
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