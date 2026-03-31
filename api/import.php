<?php
// /broker/import.php – Updated to use new modular structure
session_start();
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;
if (!$isLoggedIn && !$isAnonymous) { header("Location: ../index.html"); exit; }
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Henry';
$currentPage = 'import';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Import transakcí</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/broker.css">
  <style>
    .rates-card { 
      border: 1px solid #e5e7eb; 
      border-radius: 14px; 
      box-shadow: 0 4px 16px rgba(15,23,42,.06); 
    }
    .content-box { 
      padding: 18px; 
    }
    .upload { 
      border: 2px dashed #e5e7eb; 
      border-radius: 14px; 
      background: #f8fafc; 
      padding: 32px; 
      display: grid; 
      place-items: center; 
      cursor: pointer;
      transition: all 0.3s ease;
    }
    .upload.dragover {
      border-color: #2563eb;
      background: #eff6ff;
    }
    .progress { 
      display: none; 
      width: 100%; 
      height: 8px; 
      background: #eef2f7; 
      border-radius: 8px; 
      overflow: hidden; 
      margin-top: 14px; 
    }
    .progress > div { 
      height: 100%; 
      width: 0%; 
      background: #2563eb; 
      transition: width .25s ease; 
    }
    .alert {
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 12px;
    }
    .alert-success {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #bbf7d0;
    }
    .alert-danger {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fecaca;
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

  <div class="content-box rates-card">
    <form id="importForm">
      <div id="uploadArea" class="upload">
        <div>
          <p style="margin:0 0 4px 0; font-weight:600;">Vyberte soubory nebo je přetáhněte sem</p>
          <p style="margin:0; color:#64748b;">Podporované: CSV, PDF, HTML, XML (max. 10 MB)</p>
        </div>
        <input id="import_file" type="file" accept=".csv,.pdf,.html,.htm,.xml,.xlsx,.xls" multiple style="display:none" />
      </div>

      <div id="selectedFile" class="alert alert-success" style="display:none; margin-top:12px;"></div>

      <div id="progressBar" class="progress">
        <div id="progressFill"></div>
      </div>

      <div style="display:flex; gap:12px; margin-top:14px;">
        <button id="submitBtn" class="btn btn-primary" disabled>🚀 Spustit import</button>
      </div>
    </form>
  </div>

  <!-- Support information -->
  <div class="content-box rates-card" style="margin-top: 20px;">
    <h3 style="margin-top: 0;">Podporované formáty</h3>
    <ul style="color: #64748b;">
      <li><strong>Revolut:</strong> Trading, Crypto, Commodity (PDF/CSV)</li>
      <li><strong>Fio banka:</strong> Výpisy operací (PDF)</li>
      <li><strong>Coinbase:</strong> Transaction History (HTML/PDF)</li>
    </ul>
  </div>
</main>

<!-- Load SheetJS for Excel support -->
<script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>

<!-- Load the new modular structure -->
<script type="module" src="js/import.js?v=<?php echo time(); ?>"></script>

</body>
</html>