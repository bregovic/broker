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
$currentPage = 'portfolio';

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
  $sql="SELECT $col as val, COUNT(*) as c FROM transactions WHERE user_id=? AND $col IS NOT NULL AND $col<>'' GROUP BY $col ORDER BY $col";
  $st=$pdo->prepare($sql); $st->execute([$userId]); return $st->fetchAll();
}

/* ===== Filters ===== */
$symbol     = trim($_GET['symbol'] ?? '');
$currency   = trim($_GET['currency'] ?? '');
$platform   = trim($_GET['platform'] ?? '');
$product    = trim($_GET['product_type'] ?? '');
$transtype  = trim($_GET['trans_type'] ?? '');
$dateFrom   = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo     = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$groupMode = isset($_GET['cons']) ? $_GET['cons'] : 'none';
$grouped = ($groupMode !== 'none');
$lookup    = isset($_GET['lookup']) ? trim($_GET['lookup']) : '';

/* ===== Data ===== */
$rows=[];
if ($pdo && $currentUserId) {
  $sql="SELECT trans_id,date,id,amount,price,ex_rate,amount_cur,currency,amount_czk,platform,product_type,trans_type,fees
        FROM transactions WHERE user_id = ?";
  $params = [$currentUserId];
  if($symbol!==''){ $sql.=" AND id = ?"; $params[]=$symbol; }
  if($currency!==''){ $sql.=" AND currency = ?"; $params[]=$currency; }
  if($platform!==''){ $sql.=" AND platform = ?"; $params[]=$platform; }
  if($product!==''){ $sql.=" AND product_type = ?"; $params[]=$product; }
  if($transtype!==''){ $sql.=" AND trans_type = ?"; $params[]=$transtype; }
  if($dateFrom!==''){ $sql.=" AND date >= ?"; $params[]=$dateFrom; }
  if($dateTo!==''){ $sql.=" AND date <= ?"; $params[]=$dateTo; }
  $sql.=" ORDER BY date DESC, trans_id DESC LIMIT 2000";
  $stmt=$pdo->prepare($sql); $stmt->execute($params);
  $rows=$stmt->fetchAll();
}

/* ===== Lookups ===== */
$ids = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'id') : [];
$currencies = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'currency') : [];
$platforms  = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'platform') : [];
$products   = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'product_type') : [];
$types      = $pdo && $currentUserId ? getLookupWithCounts($pdo,$currentUserId,'trans_type') : [];


/* ===== Aggregation when grouped ===== */
$rows_grouped = [];
if ($grouped && !empty($rows)) {
  $groups = [];
  foreach ($rows as $r) {
    if ($groupMode === 'item') {
      $key = $r['id'];
      $label = $r['id'];
      $currency = $r['currency'];
      $platform = $r['platform'];
    } elseif ($groupMode === 'area') {
      $key = $r['product_type'];
      $label = $r['product_type'];
      $currency = '-';
      $platform = '-';
    } else { /* all */
      $key = '__ALL__';
      $label = 'Vše';
      $currency = '-';
      $platform = '-';
    }

    if (!isset($groups[$key])) {
      $groups[$key] = [
        'id' => $label,
        'currency' => $currency,
        'platform' => $platform,
        'tx_count' => 0,
        'buy_qty' => 0.0,
        'sell_qty' => 0.0,
        'net_qty' => 0.0,
        'buy_czk' => 0.0,
        'sell_czk' => 0.0,
        'div_czk' => 0.0,
        'fees_czk' => 0.0,
        'last_date' => $r['date']
      ];
    }
    $g =& $groups[$key];
    $g['tx_count']++;
    // Aktualizuj sloupce (pro 'item' necháváme poslední známou měnu/platformu)
    if ($groupMode === 'item') {
      $g['currency'] = $r['currency'];
      $g['platform'] = $r['platform'];
    }

    $tt = strtolower($r['trans_type']);
    if ($tt === 'buy') {
      $g['buy_qty'] += (float)$r['amount'];
      $g['net_qty'] += (float)$r['amount'];
      $g['buy_czk'] += (float)$r['amount_czk'];
    } elseif ($tt === 'sell') {
      $g['sell_qty'] += (float)$r['amount'];
      $g['net_qty'] -= (float)$r['amount'];
      $g['sell_czk'] += (float)$r['amount_czk'];
    } elseif ($tt === 'dividend') {
      $g['div_czk'] += (float)$r['amount_czk'];
    }
    $g['fees_czk'] += (float)$r['fees'];
    if ($r['date'] > $g['last_date']) $g['last_date'] = $r['date'];
    unset($g);
  }
  $rows_grouped = array_values($groups);
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portfolio – Transakce</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="css/live_quotes.css">
  <style>
    .rates-card { border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 4px 16px rgba(15,23,42,.06); }
    .table { width:100%; border-collapse:separate; border-spacing:0; }
    .table thead th { position:relative; user-select:none; cursor:pointer; font-weight:600; color:#64748b; letter-spacing:.2px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
    .table th, .table td { padding:12px 12px; }
    .table tbody tr { border-bottom:1px solid #eef2f7; }
    .table tbody tr:hover { background:#f9fbff; }
    .th-sort { display:inline-flex; align-items:center; gap:6px; }
    .th-sort .dir { font-size:12px; opacity:.7; }
    .table-container { overflow-x:auto; }
    .input, select.input, input[type="date"].input { padding:12px 14px; border-radius:10px; border:1px solid #e5e7eb; background:#fff; font-size:15px; color:#0f172a; }
    .filter-grid { display:grid; gap:14px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .content-box { padding:18px 18px 16px; }
    .page-header { margin-bottom:14px; }
    .btn { padding:10px 14px; border-radius:10px; }
    .switchline { display:flex; align-items:center; gap:10px; }
    .switchline input[type="checkbox"] { width:18px; height:18px; }
  
    /* Wider layout */
    .nav-container, .main-content { max-width: 1320px; }
    @media (min-width: 1600px){ .nav-container, .main-content { max-width: 1440px; } }

    /* Numeric alignment + generous widths */
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .w-qty { min-width: 140px; }
    .w-price { min-width: 160px; }
    .w-exrate { min-width: 150px; }
    .w-amcur { min-width: 170px; }
    .w-amczk { min-width: 180px; }
    .w-fee { min-width: 140px; }
    .w-count { min-width: 110px; text-align: center; }
    .w-date { min-width: 130px; }
    .w-text { min-width: 160px; }
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

  <?php
$groupLabel = 'Přehled transakcí';
if ($groupMode === 'item')  $groupLabel = 'Konsolidace: Položka';
if ($groupMode === 'area')  $groupLabel = 'Konsolidace: Oblast';
if ($groupMode === 'all')   $groupLabel = 'Konsolidace: Vše';
?>
<div class="content-box rates-card">
    <h3 class="collapsible-header">Filtrování <span class="toggle-icon">▼</span></h3>
    <div class="collapsible-content">
    <form method="GET" id="filterForm">
      <div class="filter-grid">
        <div>
          <label class="label" for="symbol">Ticker / ISIN</label>
          <select id="symbol" name="symbol" class="input">
            <option value="">— libovolný —</option>
            <?php if($ids) foreach ($ids as $row): $v=$row['val']; $c=$row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($symbol===$v)?'selected':''; ?>>
                <?php echo htmlspecialchars($v).' · '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="lookup">Lookup</label>
          <input type="text" id="lookup" name="lookup" class="input" value="<?php echo htmlspecialchars($lookup); ?>" placeholder="rychlé filtrování…">
        </div>
        <div>
          <label class="label" for="currency">Měna</label>
          <select id="currency" name="currency" class="input">
            <option value="">— libovolná —</option>
            <?php if($currencies) foreach($currencies as $row): $v=$row['val']; $c=$row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($currency===$v)?'selected':''; ?>>
                <?php echo htmlspecialchars($v).' · '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="platform">Platforma</label>
          <select id="platform" name="platform" class="input">
            <option value="">— libovolná —</option>
            <?php if($platforms) foreach($platforms as $row): $v=$row['val']; $c=$row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($platform===$v)?'selected':''; ?>>
                <?php echo htmlspecialchars($v).' · '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="product_type">Produkt</label>
          <select id="product_type" name="product_type" class="input">
            <option value="">— libovolný —</option>
            <?php if($products) foreach($products as $row): $v=$row['val']; $c=$row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($product===$v)?'selected':''; ?>>
                <?php echo htmlspecialchars($v).' · '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="trans_type">Typ transakce</label>
          <select id="trans_type" name="trans_type" class="input">
            <option value="">— libovolný —</option>
            <?php if($types) foreach($types as $row): $v=$row['val']; $c=$row['c']; ?>
              <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($transtype===$v)?'selected':''; ?>>
                <?php echo htmlspecialchars($v).' · '.$c; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label" for="date_from">Datum od</label>
          <input type="date" id="date_from" name="date_from" class="input" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div>
          <label class="label" for="date_to">Datum do</label>
          <input type="date" id="date_to" name="date_to" class="input" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div>
  <label class="label" for="cons">Konsolidace</label>
  <select id="cons" name="cons" class="input">
    <option value="none" <?php echo ($groupMode==='none')?'selected':''; ?>>Žádný</option>
    <option value="item" <?php echo ($groupMode==='item')?'selected':''; ?>>Položka</option>
    <option value="area" <?php echo ($groupMode==='area')?'selected':''; ?>>Oblast</option>
    <option value="all"  <?php echo ($groupMode==='all')?'selected':''; ?>>Vše</option>
  </select>
</div>
        <div style="display:flex; gap:8px; justify-content:flex-end; grid-column: 1 / -1;">
          <button type="submit" class="btn btn-primary">Filtrovat</button>
          <a class="btn btn-secondary" href="portfolio.php">Vymazat filtry</a>
        </div>
      </div>
    </form>
    </div>
  </div>

  <div class="content-box rates-card">
    <h3><?php echo htmlspecialchars($groupLabel); ?></h3>
    <?php if(($grouped && empty($rows_grouped)) || (!$grouped && empty($rows))): ?>
      <div class="alert alert-info">Žádná data pro zadané filtry.</div>
    <?php else: ?>
      <div class="table-container">
        <?php if($grouped): ?>
        <table class="table" id="txTable">
          <thead>
            <tr>
              <th data-key="id" data-type="text"><span class="th-sort">Produkt <span class="dir"></span></span></th>
              <th data-key="currency" data-type="text"><span class="th-sort">Měna <span class="dir"></span></span></th>
              <th data-key="platform" data-type="text"><span class="th-sort">Platforma <span class="dir"></span></span></th>
              <th class="w-count" data-key="tx_count" data-type="number"><span class="th-sort">Transakcí <span class="dir"></span></span></th>
              <th class="w-qty" data-key="buy_qty" data-type="number"><span class="th-sort">Nakoupeno ks <span class="dir"></span></span></th>
              <th class="w-qty" data-key="sell_qty" data-type="number"><span class="th-sort">Prodáno ks <span class="dir"></span></span></th>
              <th class="w-qty" data-key="net_qty" data-type="number"><span class="th-sort">Čisté ks <span class="dir"></span></span></th>
              <th class="w-amczk" data-key="buy_czk" data-type="number"><span class="th-sort">Nákupy CZK <span class="dir"></span></span></th>
              <th class="w-amczk" data-key="sell_czk" data-type="number"><span class="th-sort">Prodeje CZK <span class="dir"></span></span></th>
              <th class="w-amczk" data-key="div_czk" data-type="number"><span class="th-sort">Dividendy CZK <span class="dir"></span></span></th>
              <th class="w-fee" data-key="fees_czk" data-type="number"><span class="th-sort">Poplatky CZK <span class="dir"></span></span></th>
              <th class="w-date" data-key="last_date" data-type="date"><span class="th-sort">Posl. datum <span class="dir"></span></span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows_grouped as $g): ?>
              <tr
                data-id="<?php echo htmlspecialchars($g['id']); ?>"
                data-currency="<?php echo htmlspecialchars($g['currency']); ?>"
                data-platform="<?php echo htmlspecialchars($g['platform']); ?>"
                data-tx_count="<?php echo (int)$g['tx_count']; ?>"
                data-buy_qty="<?php echo (float)$g['buy_qty']; ?>"
                data-sell_qty="<?php echo (float)$g['sell_qty']; ?>"
                data-net_qty="<?php echo (float)$g['net_qty']; ?>"
                data-buy_czk="<?php echo (float)$g['buy_czk']; ?>"
                data-sell_czk="<?php echo (float)$g['sell_czk']; ?>"
                data-div_czk="<?php echo (float)$g['div_czk']; ?>"
                data-fees_czk="<?php echo (float)$g['fees_czk']; ?>"
                data-last_date="<?php echo htmlspecialchars($g['last_date']); ?>"
              >
                <td><?php echo htmlspecialchars($g['id']); ?></td>
                <td><?php echo htmlspecialchars($g['currency']); ?></td>
                <td><?php echo htmlspecialchars($g['platform']); ?></td>
                <td class="w-count num"><?php echo (int)$g['tx_count']; ?></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['buy_qty'], 2, ',', ' ')); ?></span></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['sell_qty'], 2, ',', ' ')); ?></span></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['net_qty'], 2, ',', ' ')); ?></span></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['buy_czk'], 2, ',', ' ')); ?></span></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['sell_czk'], 2, ',', ' ')); ?></span></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['div_czk'], 2, ',', ' ')); ?></span></td>
                <td><span class="num"><?php echo htmlspecialchars(number_format((float)$g['fees_czk'], 2, ',', ' ')); ?></span></td>
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
              <th data-key="id" data-type="text"><span class="th-sort">Ticker / ISIN <span class="dir"></span></span></th>
              <th class="w-qty" data-key="amount" data-type="number"><span class="th-sort">Množství <span class="dir"></span></span></th>
              <th class="w-price" data-key="price" data-type="number"><span class="th-sort">Cena za jednotku <span class="dir"></span></span></th>
              <th class="w-exrate" data-key="ex_rate" data-type="number"><span class="th-sort">Směnný kurz <span class="dir"></span></span></th>
              <th class="w-amcur" data-key="amount_cur" data-type="number"><span class="th-sort">Částka (pův. měna) <span class="dir"></span></span></th>
              <th data-key="currency" data-type="text"><span class="th-sort">Měna <span class="dir"></span></span></th>
              <th class="w-amczk" data-key="amount_czk" data-type="number"><span class="th-sort">Částka CZK <span class="dir"></span></span></th>
              <th data-key="platform" data-type="text"><span class="th-sort">Platforma <span class="dir"></span></span></th>
              <th data-key="product_type" data-type="text"><span class="th-sort">Produkt <span class="dir"></span></span></th>
              <th data-key="trans_type" data-type="text"><span class="th-sort">Typ <span class="dir"></span></span></th>
              <th class="w-fee" data-key="fees" data-type="number"><span class="th-sort">Poplatky <span class="dir"></span></span></th>
            </tr>
          </thead>
          <tbody>
            <?php 
            // Smart Number Formatter helper
            $fmt = function($val) {
                $f = (float)$val;
                if($f == 0) return '0,00';
                
                // Max 8 decimals, space thousands separator
                $res = number_format($f, 8, ',', ' ');
                
                // Trim trailing zeros from decimal part
                // Regex: match comma followed by zeros at end OR comma+digits then zeros at end
                $res = preg_replace('/(\,0+)$|(\,[0-9]*?)0+$/', '$2', $res);
                $res = rtrim($res, ','); // remove potential trailing comma
                
                // Aesthetic fix: Ensure at least 2 decimal places for standard look
                if (strpos($res, ',') === false) {
                    $res .= ',00';
                } else {
                    $parts = explode(',', $res);
                    if (strlen($parts[1]) < 2) {
                        $res .= '0';
                    }
                }
                
                return $res;
            };
            foreach($rows as $r): ?>
            <tr
              data-date="<?php echo htmlspecialchars($r['date']); ?>"
              data-id="<?php echo htmlspecialchars($r['id']); ?>"
              data-amount="<?php echo (float)$r['amount']; ?>"
              data-price="<?php echo (float)$r['price']; ?>"
              data-ex_rate="<?php echo (float)$r['ex_rate']; ?>"
              data-amount_cur="<?php echo (float)$r['amount_cur']; ?>"
              data-currency="<?php echo htmlspecialchars($r['currency']); ?>"
              data-amount_czk="<?php echo (float)$r['amount_czk']; ?>"
              data-platform="<?php echo htmlspecialchars($r['platform']); ?>"
              data-product_type="<?php echo htmlspecialchars($r['product_type']); ?>"
              data-trans_type="<?php echo htmlspecialchars($r['trans_type']); ?>"
              data-fees="<?php echo (float)$r['fees']; ?>"
            >
              <td><?php echo htmlspecialchars($r['date']); ?></td>
              <td><?php echo htmlspecialchars($r['id']); ?></td>
              <td><span class="num"><?php echo htmlspecialchars($fmt($r['amount'])); ?></span></td>
              <td><span class="num"><?php echo htmlspecialchars($fmt($r['price'])); ?></span></td>
              <td><span class="num"><?php echo htmlspecialchars($fmt($r['ex_rate'])); ?></span></td>
              <td><span class="num"><?php echo htmlspecialchars($fmt($r['amount_cur'])); ?></span></td>
              <td><?php echo htmlspecialchars($r['currency']); ?></td>
              <td><span class="num"><?php echo htmlspecialchars(number_format((float)$r['amount_czk'], 2, ',', ' ')); ?></span></td>
              <td><?php echo htmlspecialchars($r['platform']); ?></td>
              <td><?php echo htmlspecialchars($r['product_type']); ?></td>
              <td><?php echo htmlspecialchars($r['trans_type']); ?></td>
              <td><span class="num"><?php echo htmlspecialchars($fmt($r['fees'])); ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
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
</script>

<script>
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
      // Filtruj pokud je něco vyplněno, nebo pokud bylo smazáno (reset)
      submitNow();
    }, 350);
  }
  lookup.addEventListener('input', schedule);
  lookup.addEventListener('change', schedule);
  lookup.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); submitNow(); } });
})();
</script>

<script src="js/table-scroll.js"></script>
<script src="js/collapsible.js"></script>
<!-- Tip: pro automatické zaokrouhlení přidejte k číselným polím class="round2" a step="0.01" -->
</body>
</html>