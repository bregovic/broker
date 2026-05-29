<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

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
    return 0;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
try {
    $pdo = get_pdo();
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'DB Connection failed']);
    exit;
}

// --- 1. Load user's base_currency from user_settings ---
$baseCurrency = 'CZK';
try {
    $stmtSettings = $pdo->prepare("SELECT base_currency FROM user_settings WHERE user_id = ?");
    $stmtSettings->execute([$userId]);
    $settingsRow = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    if ($settingsRow && !empty($settingsRow['base_currency'])) {
        $baseCurrency = strtoupper(trim($settingsRow['base_currency']));
    }
} catch (Exception $e) {
    // Silently fallback to CZK
}

// --- 2. Load exchange rates (latest per currency, rate = CZK per 1 unit) ---
$rates = ['CZK' => 1.0];
try {
    $rStmt = $pdo->query("SELECT r.currency, r.rate, r.amount FROM rates r 
                         INNER JOIN (SELECT currency, MAX(date) as max_date FROM rates GROUP BY currency) m 
                         ON r.currency = m.currency AND r.date = m.max_date");
    if ($rStmt) {
        while($r = $rStmt->fetch(PDO::FETCH_ASSOC)) {
            $amt = (float)$r['amount'];
            $rates[$r['currency']] = $amt > 0 ? (float)$r['rate'] / $amt : 0;
        }
    }
} catch (Exception $e) {
    // Continue with defaults
}

// Helper: convert CZK amount to base currency
$baseCurrencyRate = $rates[$baseCurrency] ?? 1.0;
function convertToBase(float $amountCzk, float $baseRate): float {
    if ($baseRate <= 0) return $amountCzk;
    return $amountCzk / $baseRate;
}

// --- 3. Detect which columns exist (ticker vs id) ---
$tickerExpr = 'id'; // default fallback
try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'pgsql') {
        $colCheck = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name='transactions' AND column_name IN ('ticker','id')");
        $colCheck->execute();
        $cols = $colCheck->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('ticker', $cols) && in_array('id', $cols)) {
            $tickerExpr = "COALESCE(ticker, id)";
        } elseif (in_array('ticker', $cols)) {
            $tickerExpr = 'ticker';
        } else {
            $tickerExpr = 'id';
        }
    } else {
        // MySQL: check for ticker column
        $colCheck = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'ticker'");
        if ($colCheck && $colCheck->fetch()) {
            $tickerExpr = "COALESCE(ticker, id)";
        } else {
            $tickerExpr = 'id';
        }
    }
} catch (Exception $e) {
    // Fallback: try ticker, if it fails use id
    $tickerExpr = 'id';
}

// --- 4. Query dividend/tax transactions ---
// Include 'Tax' alongside 'Dividend' and 'Withholding' since IBKR parser uses 'Tax' for withholding tax
$sql = "SELECT 
            trans_id AS id, 
            date, 
            $tickerExpr AS ticker, 
            trans_type AS type, 
            amount_cur AS amount, 
            currency, 
            amount_czk, 
            platform, 
            '' AS notes 
        FROM transactions 
        WHERE user_id = ? AND trans_type IN ('Dividend', 'Withholding', 'Tax') 
        ORDER BY date DESC, trans_id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalize data
    $normalizedRows = [];
    $total_div_czk = 0.0;
    $total_tax_czk = 0.0;
    $byCurrency = [];

    foreach ($rows as $row) {
        // Normalize type: treat 'Tax' same as 'Withholding' for frontend display
        $rawType = $row['type'];
        $displayType = ($rawType === 'Tax') ? 'Withholding' : $rawType;

        $item = [
            'id' => (int)$row['id'],
            'date' => $row['date'],
            'ticker' => strtoupper(trim($row['ticker'] ?? '')),
            'type' => $displayType,
            'amount' => (float)$row['amount'],
            'currency' => strtoupper(trim($row['currency'])),
            'amount_czk' => (float)$row['amount_czk'],
            'platform' => $row['platform'],
            'notes' => $row['notes']
        ];
        $normalizedRows[] = $item;

        $val = abs($item['amount_czk']);
        $cur = $item['currency'];
        if (!isset($byCurrency[$cur])) {
            $byCurrency[$cur] = ['div' => 0.0, 'tax' => 0.0];
        }

        if ($displayType === 'Dividend') {
            $total_div_czk += $val;
            $byCurrency[$cur]['div'] += abs($item['amount']);
        } elseif ($displayType === 'Withholding') {
            $total_tax_czk += $val;
            $byCurrency[$cur]['tax'] += abs($item['amount']);
        }
    }

    $total_net_czk = $total_div_czk - $total_tax_czk;

    // Convert CZK totals to base currency
    $total_div_base = convertToBase($total_div_czk, $baseCurrencyRate);
    $total_tax_base = convertToBase($total_tax_czk, $baseCurrencyRate);
    $total_net_base = convertToBase($total_net_czk, $baseCurrencyRate);

    $stats = [
        'total_div_czk' => $total_div_czk,
        'total_tax_czk' => $total_tax_czk,
        'total_net_czk' => $total_net_czk,
        'total_div_base' => $total_div_base,
        'total_tax_base' => $total_tax_base,
        'total_net_base' => $total_net_base,
        'base_currency' => $baseCurrency,
        'count' => count($normalizedRows),
        'by_currency' => $byCurrency
    ];

    echo json_encode([
        'success' => true, 
        'data' => $normalizedRows,
        'stats' => $stats
    ]);
} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
?>
