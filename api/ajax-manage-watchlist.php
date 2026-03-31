<?php
/**
 * AJAX Handler for Watchlist Management
 * 
 * Handles:
 * 1. Loading available tickers with watch status
 * 2. Saving user's watchlist
 * 3. Batch adding new tickers
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// 1. Auth Check - reused from other files
function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) {
            foreach ($candidates as $k) if (isset($u[$k]) && is_numeric($u[$k])) return (int)$u[$k];
        } elseif (is_object($u)) {
            foreach ($candidates as $k) if (isset($u->$k) && is_numeric($u->$k)) return (int)$u->$k;
        }
    }
    return null;
}

$userId = resolveUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

// 2. DB Connection
try {
    $paths = [__DIR__.'/env.local.php', __DIR__.'/php/env.local.php', __DIR__.'/../env.local.php'];
    foreach($paths as $p) { if(file_exists($p)) { require_once $p; break; } }
    
    if (!defined('DB_HOST')) throw new Exception("DB Config missing");
    
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Ensure table exists (Auto-migration)
    $pdo->exec("CREATE TABLE IF NOT EXISTS watch (
        user_id INT NOT NULL,
        ticker VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, ticker)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (Exception $e) {
    echo json_encode(['success'=>false, 'error'=>'DB Error: '.$e->getMessage()]);
    exit;
}

// 3. Handle Actions
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    if ($action === 'load') {
        // Load all tickers + status
        // Join with Portfolio (trans) to identify owned assets
        $sql = "SELECT q.id, q.company_name, q.currency, q.asset_type, 
                       q.current_price, w.created_at as watched_since,
                CASE WHEN w.ticker IS NOT NULL THEN 1 ELSE 0 END as is_watched,
                CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END as is_owned
                FROM live_quotes q
                LEFT JOIN watch w ON q.id = w.ticker AND w.user_id = ?
                LEFT JOIN (
                    SELECT DISTINCT id FROM transactions WHERE user_id = ?
                ) p ON q.id = p.id
                WHERE q.status = 'active'
                ORDER BY is_owned DESC, is_watched DESC, q.id ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $userId]);
        $rows = $stmt->fetchAll();
        
        echo json_encode(['success'=>true, 'data'=>$rows]);

    } elseif ($action === 'save') {
        // Save watchlist selection
        $tickers = $input['tickers'] ?? []; // List of ticker IDs to watch
        
        if (!is_array($tickers)) throw new Exception("Invalid tickers list");
        
        $pdo->beginTransaction();
        
        // Remove all for this user first (simple sync)
        $msg = "Updated";
        
        // Strategy: 
        // 1. Get current owned tickers (we should enforce watching owned?) 
        // -> User req: "Automaticky se mu dá do sledování jen to co má v portofilu."
        // This usually means "Display owned by default", but if we save to DB, we can just save what user selected.
        // Let's rely on frontend sending the Full List (owned + explicit watched).
        
        $del = $pdo->prepare("DELETE FROM watch WHERE user_id = ?");
        $del->execute([$userId]);
        
        if (!empty($tickers)) {
            $ins = $pdo->prepare("INSERT INTO live_quotes (id, status) VALUES (?, 'active') ON DUPLICATE KEY UPDATE status='active'"); 
            // Ensure they exist in live quotes just in case (e.g. if ID was manually typed) - though select UI handles this.
            
            $insWatch = $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)");
            
            foreach ($tickers as $t) {
                $t = strtoupper(trim($t));
                if (!$t) continue;
                $insWatch->execute([$userId, $t]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success'=>true, 'message'=>'Seznam uložen']);

    } elseif ($action === 'add_new') {
        // Batch Add New Tickers
        $text = $input['text'] ?? '';
        $type = $input['type'] ?? 'stock';
        $rawList = preg_split('/[\s,]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $added = 0;
        
        $insQuote = $pdo->prepare("INSERT INTO live_quotes (id, asset_type, status, last_fetched) VALUES (?, ?, 'active', NULL) ON DUPLICATE KEY UPDATE asset_type=VALUES(asset_type)");
        $insWatch = $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)");
        
        foreach ($rawList as $t) {
            $t = strtoupper(trim($t));
            if (strlen($t) < 2 || strlen($t) > 20) continue;
            $thisType = $type;
            if ($type === 'stock') {
                $knownCrypto = ['BTC','ETH','SOL','ADA','DOT','XRP','LTC','DOGE','USDT'];
                if (in_array($t, $knownCrypto)) $thisType = 'crypto';
            }
            $insQuote->execute([$t, $thisType]);
            $insWatch->execute([$userId, $t]);
            $added++;
        }
        echo json_encode(['success'=>true, 'message'=>"Přidáno $added tickerů."]);

    } elseif ($action === 'get_candidates') {
        // Pro modální okno: Vrátí seznam všech tickerů a info, zda je user sleduje
        // Limitujeme na 500 pro výkon, pokud není search
        $q = $input['q'] ?? '';
        $params = [':uid' => $userId];
        $sql = "
            SELECT 
                t.id, 
                t.company_name, 
                t.asset_type,
                CASE WHEN w.ticker IS NOT NULL THEN 1 ELSE 0 END as is_watched,
                CASE WHEN p.cnt > 0 THEN 1 ELSE 0 END as is_owned
            FROM live_quotes t
            LEFT JOIN watch w ON t.id = w.ticker AND w.user_id = :uid
            LEFT JOIN (SELECT id, COUNT(*) as cnt FROM transactions WHERE user_id = :uid GROUP BY id) p ON t.id = p.id
            WHERE t.status = 'active'
        ";
        
        if ($q !== '') {
            $sql .= " AND (t.id LIKE :q OR t.company_name LIKE :q)";
            $params[':q'] = "%$q%";
        }
        
        $sql .= " ORDER BY is_watched DESC, t.id ASC LIMIT 500";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success'=>true, 'data'=>$rows]);

    } elseif ($action === 'batch_update') {
        // Hromadná update z modálu (checkboxy)
        $tickers = $input['tickers'] ?? []; // seznam tickerů, které MAJÍ BÝT sledovány
        
        // 1. Smažeme všechny kromě vlastněných? Ne, to je nebezpečné.
        // Lepší přístup: User posílá seznam změn, nebo prostě seznam ID, které chce mít checknuté.
        // Uděláme to jako "Set state": To co pošle, bude watched. Co nepošle (a není v tom seznamu loading), neřešíme?
        // Ne, modal pošle seznam změn (added, removed) nebo full snapshot.
        // Pro jednoduchost: Modal pošle seznam 'watched_tickers' (jen ty co uživatel chce).
        // My ale musíme dát pozor, abychom nesmazali ty, co nebyly v modálu načtené (kvůli limitu).
        
        // Bezpečnější varianta: 'changes' array
        $changes = $input['changes'] ?? []; // [{ticker: 'AAA', state: true/false}, ...]
        
        $ins = $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)");
        $del = $pdo->prepare("DELETE FROM watch WHERE user_id = ? AND ticker = ?");
        
        $count = 0;
        foreach ($changes as $ch) {
            $t = $ch['ticker'];
            $s = $ch['state']; // true = add, false = remove
            
            // Check ownership protection?
            // (Front-end should handle disabling checkbox, but backend safety:)
            /*
            $isOwned = $pdo->query("SELECT COUNT(*) FROM transactions WHERE user_id=$userId AND id='$t'")->fetchColumn() > 0;
            if ($isOwned && !$s) continue; // Cannot unwatch owned
            */
            
            if ($s) {
                $ins->execute([$userId, $t]);
            } else {
                $del->execute([$userId, $t]);
            }
            $count++;
        }
        
        echo json_encode(['success'=>true, 'message'=>"Uloženo $count změn."]);
        
    } elseif ($action === 'toggle') {
        // Toggle single ticker
        $ticker = strtoupper(trim($input['ticker'] ?? ''));
        if (!$ticker) throw new Exception("Ticker missing");
        
        $op = '';
        
        // Check current
        $st = $pdo->prepare("SELECT 1 FROM watch WHERE user_id = ? AND ticker = ?");
        $st->execute([$userId, $ticker]);
        if ($st->fetch()) {
            // Remove
            $pdo->prepare("DELETE FROM watch WHERE user_id = ? AND ticker = ?")->execute([$userId, $ticker]);
            $op = 'removed';
        } else {
            // Add (ensure quote exists first?)
            // Assuming quote exists if we clicked on it in market view
            $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)")->execute([$userId, $ticker]);
            $op = 'added';
        }
        echo json_encode(['success'=>true, 'operation'=>$op]);

    } else {
        throw new Exception("Unknown action");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
}
