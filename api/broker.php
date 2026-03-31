<?php
// Start session
session_start();

// Check if user is logged in
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$isAnonymous = isset($_SESSION['anonymous']) && $_SESSION['anonymous'] === true;

// If not logged in and not anonymous, redirect to login page
if (!$isLoggedIn && !$isAnonymous) {
    header("Location: ../index.html");
    exit;
}

// Get user name for display
$userName = isset($_SESSION['name']) ? $_SESSION['name'] : 'Uživatel';
$currentPage = 'broker';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio Tracker</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/broker.css">
</head>
<body>
    <!-- Header Navigation -->
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
      <a href="../index_menu.php" class="btn btn-secondary">Menu</a>
      <a href="../php/logout.php" class="btn btn-danger">Odhlásit se</a>
    </div>
  </nav>
</header>

    <!-- Main Content -->
    <main class="main-content">
        
        </div>

        <!-- Reports Grid -->
        <div class="reports-grid">
            <a href="portfolio.php" class="report-card">
                <div class="report-icon">📈</div>
                <h3 class="report-title">Přehled transakcí</h3>
                <p class="report-description">
                    Celkový přehled vašeho portfolia, zisků a ztrát. 
                    Grafy a statistiky výkonnosti.
                </p>
            </a>

            <a href="bal.php" class="report-card">
                <div class="report-icon">🧾</div>
                <h3 class="report-title">Aktuální portfolio</h3>
                <p class="report-description">
                    Detailní přehled aktuálně držených titulů, nákupních cen a
                    aktuálních tržních hodnot v&nbsp;CZK.
                </p>
            </a>

            <a href="sal.php" class="report-card">
                <div class="report-icon">💹</div>
                <h3 class="report-title">Realizované P&amp;L</h3>
                <p class="report-description">
                    Analýza uzavřených obchodů, realizovaných zisků a ztrát
                    pro daňovou evidenci a reporting.
                </p>
            </a>

            <a href="import.php" class="report-card">
                <div class="report-icon">📥</div>
                <h3 class="report-title">Import transakcí</h3>
                <p class="report-description">
                    Importujte transakce z různých brokerů a bank. 
                    Podporuje CSV, Excel a další formáty.
                </p>
            </a>

            <a href="rates.php" class="report-card">
                <div class="report-icon">💱</div>
                <h3 class="report-title">Směnné kurzy</h3>
                <p class="report-description">
                    Aktuální směnné kurzy měn pro přepočet hodnot portfolia. 
                    Automatické aktualizace kurzů.
                </p>
            </a>

            <a href="div.php" class="report-card">
                <div class="report-icon">💰</div>
                <h3 class="report-title">Dividendy</h3>
                <p class="report-description">
                    Sledování dividend a výnosů z investic. 
                    Historie výplat a projekce budoucích příjmů.
                </p>
            </a>

            <a href="market.php" class="report-card">
                <div class="report-icon">🌍</div>
                <h3 class="report-title">Přehled trhu</h3>
                <p class="report-description">
                    Poslední denní závěrky z&nbsp;broker_data a změny vůči 
                    předchozímu dni pro jednotlivé tituly.
                </p>
            </a>
        </div>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.getAttribute('href') === '#') {
                        e.preventDefault();
                    }
                    
                    navLinks.forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                });
            });

            const reportCards = document.querySelectorAll('.report-card');
            
            reportCards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (this.getAttribute('href') === '#') {
                        e.preventDefault();
                        this.style.transform = 'translateY(-2px) scale(0.98)';
                        setTimeout(() => {
                            this.style.transform = '';
                        }, 150);
                    }
                });
            });
        });
    </script>
</body>
</html>