<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. Resolve User ID
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
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

// 2. DB Connect
$paths = [
    __DIR__.'/env.local.php', 
    __DIR__.'/php/env.local.php', 
    __DIR__.'/../env.local.php', 
    __DIR__.'/../../env.local.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.local.php',
    __DIR__.'/env.php',
    __DIR__.'/../env.php',
    __DIR__.'/../../env.php',
    $_SERVER['DOCUMENT_ROOT'] . '/env.php'
];
foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }

if (!defined('DB_HOST')) {
    echo json_encode(['success'=>false, 'error'=>'DB Config Missing']);
    exit;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'Database connection failed']);
    exit;
}

// 3. Helper Functions (Ported from sal.php)

function calculateAveragePurchasePrice($pdo, $userId, $ticker, $sellDate, $platform = null) {
  $sql = "SELECT date, amount, price, amount_czk, ex_rate, currency 
          FROM transactions 
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
               FROM transactions 
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
  
  $totalBought = 0;
  $totalCostCZK = 0;
  $totalSold = 0;
  $weightedExRate = 0;
  $totalCostOriginal = 0;
  
  foreach ($purchases as $p) {
    $totalBought += (float)$p['amount'];
    $totalCostCZK += abs((float)$p['amount_czk']);
    
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
  
  $avgPriceCZK = $totalCostCZK / $totalBought;
  $avgPriceOriginal = $totalCostOriginal / $totalBought;
  $avgExRate = $totalCostOriginal > 0 ? $weightedExRate / $totalCostOriginal : 0;
  
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
  return $diff->days >= 1095;
}

// 4. Main Logic
try {
  $sql = "SELECT trans_id, date, id, amount, price, ex_rate, amount_cur, currency, amount_czk, platform, fees, notes
          FROM transactions 
          WHERE user_id = ? 
          AND trans_type = 'Sell' 
          AND (product_type = 'Stock' OR product_type = 'Crypto') 
          ORDER BY date DESC, trans_id DESC LIMIT 2000";
          
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$userId]);
  $sales = $stmt->fetchAll();
  
  $stmt = $pdo->prepare("SELECT DISTINCT YEAR(date) FROM transactions WHERE user_id=:uid ORDER BY 1 DESC");$stmt->fetchAll();
  
  $data = [];
  $stats = [
      'net_profit' => 0,
      'realized_profit' => 0,
      'realized_loss' => 0,
      'tax_free_profit' => 0,
      'taxable_profit' => 0,
      'winning' => 0,
      'losing' => 0,
      'total_count' => 0
  ];

  foreach ($sales as $sale) {
    $avgData = calculateAveragePurchasePrice($pdo, $userId, $sale['id'], $sale['date'], $sale['platform']);
    
    $sellQty = (float)$sale['amount'];
    $sellPriceCZK = $sellQty > 0 ? abs((float)$sale['amount_czk']) / $sellQty : 0;
    
    $profitCZK = ($sellPriceCZK - $avgData['avg_price_czk']) * $sellQty;
    
    $taxTestPassed = checkTaxTest($avgData['first_purchase_date'], $sale['date']);
    
    // Stats
    $stats['total_count']++;
    if ($profitCZK > 0) {
        $stats['realized_profit'] += $profitCZK;
        $stats['winning']++;
    } else {
        $stats['realized_loss'] += abs($profitCZK);
        $stats['losing']++;
    }
    
    if ($taxTestPassed) {
        $stats['tax_free_profit'] += $profitCZK;
    } else {
        $stats['taxable_profit'] += $profitCZK;
    }
    
    $stats['net_profit'] += ($profitCZK - (float)$sale['fees']); // Assuming fees are positive number
    
    $data[] = [
        'id' => $sale['trans_id'],
        'date' => $sale['date'],
        'ticker' => $sale['id'],
        'qty' => $sellQty,
        'profit_czk' => $profitCZK,
        'net_profit_czk' => $profitCZK - (float)$sale['fees'],
        'tax_test' => $taxTestPassed,
        'holding_days' => $avgData['first_purchase_date'] ? (new DateTime($avgData['first_purchase_date']))->diff(new DateTime($sale['date']))->days : 0,
        'platform' => $sale['platform'],
        'currency' => $sale['currency']
    ];
  }
  
  echo json_encode([
      'success' => true,
      'stats' => $stats,
      'data' => $data
  ]);
  
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
