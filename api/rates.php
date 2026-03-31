<?php
if (isset($_GET['api']) && $_GET['api'] === '1') {
    // Potlacit zobrazeni chyb v HTML vystupu
    ini_set('display_errors', 0);
    ini_set('html_errors', 0);
    error_reporting(0);
    // Zachytit jakykoliv vystup (napr. warningy z session_start nebo require)
    ob_start();
}
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) {
    header("Location: ../index.html");
    exit;
}
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Uživatel';
$currentPage = 'rates';

$pdo = null;
// Load environment variables
$envPaths = [
    __DIR__ . '/../env.local.php',
    __DIR__ . '/env.local.php',
    __DIR__ . '/php/env.local.php',
    '../env.local.php',
    'php/env.local.php',
    '../php/env.local.php'
];

foreach ($envPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

try {
    if (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
} catch (Exception $e) {
    // Database connection failed, pdo remains null
}

// --- API HANDLER (inserted to avoid 404 on new files) ---
if (isset($_GET['api']) && $_GET['api'] === '1') {
    // Zahodit vse co se doted vypsalo (warningy, chyby, whitespace)
    ob_end_clean();
    header('Content-Type: application/json');
    
    if (!$pdo) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'DB Connection failed']);
        exit;
    }

    function findRateApi($pdo, $currency, $date, $nearest = false) {
        if ($currency === 'CZK') return 1;
        
        // Try exact match first
        $stmt = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency = ? AND date = ? LIMIT 1");
        $stmt->execute([$currency, $date]);
        $row = $stmt->fetch();
        
        if ($row) {
            return $row['amount'] > 0 ? $row['rate'] / $row['amount'] : $row['rate'];
        }
        
        if ($nearest) {
            // Find nearest past rate
            $stmt = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency = ? AND date <= ? ORDER BY date DESC LIMIT 1");
            $stmt->execute([$currency, $date]);
            $row = $stmt->fetch();
            if ($row) {
                return $row['amount'] > 0 ? $row['rate'] / $row['amount'] : $row['rate'];
            }
        }
        
        return null;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $currency = $_GET['currency'] ?? '';
        $date = $_GET['date'] ?? '';
        $nearest = !empty($_GET['nearest']);
        
        if (!$currency || !$date) {
            echo json_encode(['ok' => false, 'message' => 'Missing params']);
            exit;
        }
        
        $rate = findRateApi($pdo, $currency, $date, $nearest);
        
        if ($rate !== null) {
            echo json_encode(['ok' => true, 'rate' => $rate]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Rate not found']);
        }
        
    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $requests = $input['requests'] ?? [];
        $nearest = !empty($input['nearest']);
        
        $results = [];
        
        foreach ($requests as $req) {
            $cur = $req['currency'] ?? '';
            $d = $req['date'] ?? '';
            if (!$cur || !$d) continue;
            
            $key = "{$d}|{$cur}";
            $rate = findRateApi($pdo, $cur, $d, $nearest);
            
            if ($rate !== null) {
                $results[$key] = $rate;
            }
        }
        
        echo json_encode(['ok' => true, 'rates' => $results]);
    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    }
    exit;
}
// --- END API HANDLER ---

function getCurrencies($pdo) {
    if (!$pdo) return [];
    try {
        $stmt = $pdo->prepare("SELECT DISTINCT currency FROM rates ORDER BY currency");
        $stmt->execute();
        $currencies = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $currencyNames = [
            'USD' => 'US Dollar','EUR' => 'Euro','GBP' => 'British Pound','JPY' => 'Japanese Yen',
            'CHF' => 'Swiss Franc','CAD' => 'Canadian Dollar','AUD' => 'Australian Dollar',
            'CZK' => 'Czech Koruna','PLN' => 'Polish Zloty','HUF' => 'Hungarian Forint',
            'SEK' => 'Swedish Krona','NOK' => 'Norwegian Krone','DKK' => 'Danish Krone'
        ];
        $result=[];
        foreach ($currencies as $code) {
            $result[]=['code'=>$code,'name'=>$currencyNames[$code]??$code];
        }
        return $result;
    } catch (Exception $e) { return []; }
}
function getExchangeRates($currency='',$dateFrom='',$dateTo='',$pdo=null) {
    if (!$pdo) return [];
    try {
        $sql="SELECT rate_id AS id,date,currency,rate,amount,source,created_at,updated_at FROM rates WHERE 1=1";
        $params=[];
        if($currency){$sql.=" AND currency=?";$params[]=$currency;}
        if($dateFrom){$sql.=" AND date>=?";$params[]=$dateFrom;}
        if($dateTo){$sql.=" AND date<=?";$params[]=$dateTo;}
        $sql.=" ORDER BY date DESC,currency ASC LIMIT 100";
        $stmt=$pdo->prepare($sql);$stmt->execute($params);
        $rates=$stmt->fetchAll();
        foreach($rates as &$r){$r['rate_per_unit']=$r['amount']>0?$r['rate']/$r['amount']:$r['rate'];}
        return $rates;
    } catch(Exception $e){return [];}
}
$selectedCurrency=$_GET['currency']??'';
$dateFrom=$_GET['date_from']??date('Y-m-01');
$dateTo=$_GET['date_to']??date('Y-m-t');
$currencies=getCurrencies($pdo);
$exchangeRates=getExchangeRates($selectedCurrency,$dateFrom,$dateTo,$pdo);
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Směnné kurzy</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/broker.css">
  <link rel="stylesheet" href="css/broker-overrides.css">
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
    .filter-grid { display:grid; grid-template-columns:1.3fr 1fr 1fr .6fr; gap:14px; }
    .content-box { padding:18px 18px 16px; }
    .page-header { margin-bottom:14px; }
    .btn { padding:10px 14px; border-radius:10px; }
    .upload-area { display:grid; grid-template-columns:54px 1fr; align-items:center; gap:12px; border:1px dashed #e5e7eb; border-radius:12px; padding:14px; background:#f8fafc; }
    .upload-icon { font-size:28px; opacity:.8; }
    .upload-text { margin:0; font-weight:600; }
    .upload-subtext { margin:2px 0 0 0; color:#64748b; font-size:13px; }
    .file-input { display:block; margin-top:8px; }
    .table-footer { display:flex; justify-content:flex-end; padding:8px 12px; color:#64748b; font-size:13px; }
    .modal-backdrop{position:fixed; inset:0; background:rgba(15,23,42,.45); display:none; align-items:center; justify-content:center; z-index:60;}
    .modal{background:#fff; border-radius:14px; width:100%; max-width:420px; padding:18px; box-shadow:0 20px 60px rgba(2,6,23,.35); border:1px solid #e5e7eb;}
    .modal h3{margin:0 0 10px 0;}
    .modal .actions{display:flex; gap:8px; justify-content:flex-end; margin-top:12px;}
    @media (max-width: 860px){ .filter-grid { grid-template-columns:1fr; } }
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

  <div class="content-box rates-card">
    <h3>Import kurzů</h3>
    <div style="display:flex; gap:12px; flex-wrap:wrap;">
      <button class="btn btn-primary btn-lg" onclick="openCnbDialog()">🏦 Import z ČNB</button>
      <button class="btn btn-secondary btn-lg" onclick="openCnbYearDialog()">📅 Import ročních kurzů ČNB</button>
      <button class="btn btn-secondary btn-lg" onclick="showCSVImport()">📄 Import CSV</button>
    </div>
  </div>

  <div class="content-box rates-card" id="csvImportForm" style="display:none;">
    <h3>Import CSV souboru</h3>
    <form id="csvForm" action="csv-import.php" method="post" enctype="multipart/form-data">
      <div class="upload-area">
        <span class="upload-icon">📄</span>
        <div>
          <p class="upload-text">Přesuňte sem soubor, nebo klikněte pro výběr</p>
          <p class="upload-subtext">Podporované: CSV (čárka / středník)</p>
          <input type="file" id="csv_file" name="csvFile" class="file-input" accept=".csv" required>
        </div>
      </div>
      <div style="display:flex; gap:8px; margin-top:12px;">
        <input type="text" id="csv_source" name="source" class="input" placeholder="Zdroj (např. csv_import)" style="flex:1;">
        <button type="submit" class="btn btn-primary">Nahrát a importovat</button>
        <button type="button" class="btn btn-secondary" onclick="hideCSVImport()">Zrušit</button>
      </div>
    </form>
  </div>

  <div class="content-box rates-card">
    <h3>Filtrování kurzů</h3>
    <form method="GET" class="filter-form">
      <div class="filter-grid">
        <div>
          <label for="currency" class="label">Měna</label>
          <select name="currency" id="currency" class="input">
            <option value="">— vyberte —</option>
            <?php foreach ($currencies as $currency): ?>
            <option value="<?php echo $currency['code']; ?>" <?php echo ($selectedCurrency===$currency['code'])?'selected':'';?>>
              <?php echo $currency['code'].' – '.$currency['name']; ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="date_from" class="label">Datum od</label>
          <input type="date" name="date_from" id="date_from" class="input" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div>
          <label for="date_to" class="label">Datum do</label>
          <input type="date" name="date_to" id="date_to" class="input" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div style="display:flex; align-items:end;">
          <button type="submit" class="btn btn-primary" style="width:100%;">Filtrovat</button>
        </div>
      </div>
    </form>
  </div>

  <div class="content-box rates-card">
    <h3>Seznam kurzů</h3>
    <?php if (empty($exchangeRates)): ?>
      <div class="alert alert-info">Žádné kurzy nenalezeny. Upravte filtr nebo naimportujte data.</div>
    <?php else: ?>
      <div class="table-container">
        <table class="table" id="ratesTable">
          <thead>
            <tr>
              <th data-key="date" data-type="date"><span class="th-sort">Datum <span class="dir"></span></span></th>
              <th data-key="currency" data-type="text"><span class="th-sort">Měna <span class="dir"></span></span></th>
              <th data-key="amount" data-type="number"><span class="th-sort">Částka <span class="dir"></span></span></th>
              <th data-key="rate" data-type="number"><span class="th-sort">Kurz (za částku) <span class="dir"></span></span></th>
              <th data-key="unit" data-type="number"><span class="th-sort">Kurz / 1 jednotku <span class="dir"></span></span></th>
              <th data-key="created_at" data-type="date"><span class="th-sort">Vytvořeno <span class="dir"></span></span></th>
              <th data-key="updated_at" data-type="date"><span class="th-sort">Upraveno <span class="dir"></span></span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($exchangeRates as $rate): 
            $rateVal = (float)$rate['rate'];
            $unitVal = (float)$rate['rate_per_unit'];
          ?>
            <tr
              data-date="<?php echo htmlspecialchars($rate['date']); ?>"
              data-currency="<?php echo htmlspecialchars($rate['currency']); ?>"
              data-amount="<?php echo (int)$rate['amount']; ?>"
              data-rate="<?php echo $rateVal; ?>"
              data-unit="<?php echo $unitVal; ?>"
              data-created_at="<?php echo htmlspecialchars($rate['created_at']); ?>"
              data-updated_at="<?php echo htmlspecialchars($rate['updated_at']); ?>"
            >
              <td><?php echo htmlspecialchars($rate['date']); ?></td>
              <td><?php echo htmlspecialchars($rate['currency']); ?></td>
              <td><?php echo htmlspecialchars($rate['amount']); ?></td>
              <td><?php echo htmlspecialchars(number_format($rateVal,6,',',' ')); ?></td>
              <td><?php echo htmlspecialchars(number_format($unitVal,6,',',' ')); ?></td>
              <td><?php echo htmlspecialchars($rate['created_at']); ?></td>
              <td><?php echo htmlspecialchars($rate['updated_at']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="table-footer">Zobrazeno <?php echo count($exchangeRates); ?> záznamů (max 100).</div>
    <?php endif; ?>
  </div>
</main>

<!-- Dialog pro import z ČNB -->
<div class="modal-backdrop" id="cnbModal">
  <div class="modal">
    <h3>Import kurzů z ČNB</h3>
    <p class="page-subtitle" style="margin:0 0 6px 0;">Zadejte datum, pro které chcete načíst denní kurzovní lístek.</p>
    <label class="label" for="cnbDate">Datum</label>
    <input type="date" id="cnbDate" class="input" value="<?php echo date('Y-m-d'); ?>">
    <div class="actions">
      <button class="btn btn-secondary" onclick="closeCnbDialog()">Zrušit</button>
      <button class="btn btn-primary" onclick="submitCnbImport()">Importovat</button>
    </div>
    <div id="cnbMsg" class="page-subtitle" style="display:none; margin-top:6px;"></div>
  </div>
</div>

<!-- Dialog pro roční import z ČNB -->
<div class="modal-backdrop" id="cnbYearModal">
  <div class="modal">
    <h3>Import ročních kurzů z ČNB</h3>
    <p class="page-subtitle" style="margin:0 0 6px 0;">Vyberte rok, pro který chcete načíst kurzovní lístek.</p>
    <label class="label" for="cnbYear">Rok</label>
    <select id="cnbYear" class="input">
      <?php
        $currentYear = (int)date('Y');
        for ($y = $currentYear; $y >= 1991; $y--) {
            echo '<option value="'.$y.'">'.$y.'</option>';
        }
      ?>
    </select>
    <div class="actions">
      <button class="btn btn-secondary" onclick="closeCnbYearDialog()">Zrušit</button>
      <button class="btn btn-primary" onclick="submitCnbYearImport()">Importovat</button>
    </div>
    <div id="cnbYearMsg" class="page-subtitle" style="display:none; margin-top:6px;"></div>
  </div>
</div>

<script>
function showCSVImport(){document.getElementById('csvImportForm').style.display='block';}
function hideCSVImport(){document.getElementById('csvImportForm').style.display='none';}

/* CNB modal */
const cnbModal = document.getElementById('cnbModal');
function openCnbDialog(){ cnbModal.style.display='flex'; document.getElementById('cnbMsg').style.display='none'; }
function closeCnbDialog(){ cnbModal.style.display='none'; }

async function submitCnbImport(){
  const el = document.getElementById('cnbDate');
  const date = el.value;
  const msg = document.getElementById('cnbMsg');
  msg.style.display='block'; msg.textContent='Importuji…';
  try{
    const form = new FormData(); form.append('date', date);
    const res = await fetch('cnb-import.php', { method:'POST', body:form });
    const json = await res.json();
    if(!json.ok){ msg.textContent = 'Chyba: ' + (json.message || 'neznámá'); return; }
    msg.textContent = `Hotovo: vloženo ${json.inserted}, aktualizováno ${json.updated}.`;
    setTimeout(()=>{ window.location.reload(); }, 800);
  }catch(e){
    msg.textContent = 'Chyba komunikace: '+e.message;
  }
}

/* CNB modal – roční import */
const cnbYearModal = document.getElementById('cnbYearModal');

function openCnbYearDialog(){
  if(!cnbYearModal) return;
  const msg = document.getElementById('cnbYearMsg');
  if(msg){ msg.style.display='none'; }
  cnbYearModal.style.display='flex';
}

function closeCnbYearDialog(){
  if(cnbYearModal){ cnbYearModal.style.display='none'; }
}

async function submitCnbYearImport(){
  const el = document.getElementById('cnbYear');
  const year = el ? el.value : '';
  const msg = document.getElementById('cnbYearMsg');
  const btn = event ? event.target : null;
  
  // Ochrana proti vicenasobnemu volani
  if(btn && btn.disabled) return;
  if(btn) btn.disabled = true;
  
  if(msg){
    msg.style.display='block';
    msg.textContent='Importuji…';
  }
  try{
    const formData = new FormData();
    formData.append('year', year);
    const res = await fetch('cnb-import-year.php', { 
      method:'POST', 
      body: formData
    });
    
    const text = await res.text();
    console.log('Response:', text);
    
    let json;
    try {
      json = JSON.parse(text);
    } catch(e) {
      if(msg) msg.textContent = 'Chyba: Neplatna odpoved ze serveru';
      if(btn) btn.disabled = false;
      return;
    }
    
    if(!json.ok){
      if(msg) msg.textContent = 'Chyba: ' + (json.message || 'neznama');
      if(btn) btn.disabled = false;
      return;
    }
    if(msg){
      msg.textContent = `Hotovo: vlozeno ${json.inserted || 0}, aktualizovano ${json.updated || 0}.`;
    }
    setTimeout(()=>{ window.location.reload(); }, 1200);
  }catch(e){
    if(msg) msg.textContent = 'Chyba komunikace: ' + e.message;
    if(btn) btn.disabled = false;
  }
}

/* Client-side sorting */
(function(){
  const table = document.getElementById('ratesTable');
  if(!table) return;
  const thead = table.querySelector('thead');
  const tbody = table.querySelector('tbody');
  let currentKey = null;
  let currentDir = 1; // 1 asc, -1 desc

  function getCellValue(tr, key, type){
    const raw = tr.dataset[key];
    if(type === 'number'){
      const n = parseFloat(raw);
      return isNaN(n) ? 0 : n;
    }
    if(type === 'date'){
      const t = Date.parse(raw);
      return isNaN(t) ? 0 : t;
    }
    return (raw||'').toString().toLowerCase();
  }

  function updateHeaderIndicators(th, dir){
    thead.querySelectorAll('th').forEach(h=>{
      const span = h.querySelector('.dir'); if(span) span.textContent='';
    });
    const span = th.querySelector('.dir');
    if(span) span.textContent = dir === 1 ? '▲' : '▼';
  }

  thead.addEventListener('click', (e)=>{
    const th = e.target.closest('th');
    if(!th) return;
    const key = th.dataset.key;
    const type = th.dataset.type || 'text';
    if(!key) return;

    if(currentKey === key){ currentDir *= -1; } else { currentKey = key; currentDir = 1; }
    updateHeaderIndicators(th, currentDir);

    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a,b)=>{
      const va = getCellValue(a, key, type);
      const vb = getCellValue(b, key, type);
      if(va<vb) return -1*currentDir;
      if(va>vb) return 1*currentDir;
      return 0;
    });
    rows.forEach(r=>tbody.appendChild(r));
  });
})();
</script>
</body>
</html>