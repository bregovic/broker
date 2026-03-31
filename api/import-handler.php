<?php
// /broker/import-handler.php
// Generic receiver for normalized transactions coming from JS importers.
// - Resolves user_id from session robustly
// - Deduplicates (fingerprint if available, else tolerant fallback)
// - Resolves FX rate from broker_exrates (fallback: CNB XML for the day)
// - Computes amount_czk if missing
// - Validates IDs (Crypto: musí obsahovat alespoň jedno písmeno; povolí 1INCH apod.)
// - Normalizes SELL amounts to positive
// - Returns counts and messages as JSON

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
session_start();
require_once __DIR__ . '/googlefinanceservice.php';

/* ===================== User ===================== */
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
list($currentUserId, $userKey) = resolveUserIdFromSession();
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Nelze určit ID uživatele ze session.']);
    exit;
}

/* ===================== DB ===================== */
try {
    $paths = [
        __DIR__.'/env.local.php', 
        __DIR__.'/../env.local.php', 
        __DIR__.'/php/env.local.php',
        '../env.local.php',
        'php/env.local.php',
        '../php/env.local.php'
    ];
    foreach ($paths as $p) { if (file_exists($p)) { require_once $p; break; } }
    if (!defined('DB_HOST')) throw new Exception('DB config nenalezen');
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Chyba DB: '.$e->getMessage()]);
    exit;
}

/* ===================== Detect fingerprint column ===================== */
$hasFingerprint = false;
try {
    $res = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'fingerprint'");
    $hasFingerprint = (bool)$res->fetch();
} catch (Exception $e) {
    $hasFingerprint = false;
}

/* ===================== Helpers: rounding, canon, fingerprint ===================== */
function canon($s) {
    return strtoupper(trim((string)$s));
}

function txFingerprint($r) {
    // Basic fields: date, id, amount, currency, trans_type, platform
    $parts = [
        canon($r['date']),
        canon($r['id']),
        sprintf('%.8f', (float)$r['amount']),
        canon($r['currency']),
        canon($r['trans_type']),
        canon($r['platform']) 
    ];
    if (!empty($r['external_id'])) $parts[] = canon($r['external_id']);
    
    return hash('sha256', implode('|', $parts));
}

/* ===================== Helpers: rates ===================== */
function fetchCnbAndStore(PDO $pdo, string $date, string $currency) {
    $url = "https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/vybrane.txt?od=$date&do=$date&mena=$currency&format=txt";
    $ctx = stream_context_create(['http'=>['timeout'=>5]]);
    $data = @file_get_contents($url, false, $ctx);
    if (!$data) return null;
    
    $lines = explode("\n", $data);
    foreach ($lines as $line) {
        $cols=explode('|',$line);
        if (count($cols)>=5 && $cols[3]===$currency) {
            $val = (float)str_replace(',','.',$cols[4]);
            $amount = (float)str_replace(',','.',$cols[2]); 
            if ($amount>1) $val = $val/$amount;
            
            try {
                $ins = $pdo->prepare("INSERT INTO rates (date,currency,rate,amount,source) VALUES (?,?,?,?, 'CNB') ON DUPLICATE KEY UPDATE rate=VALUES(rate), amount=VALUES(amount), source='CNB'");
                $ins->execute([$date, $currency, $val, 1]);
            } catch (Exception $e) {
                 // Fallback without source if column missing
                 $ins = $pdo->prepare("INSERT INTO rates (date,currency,rate,amount) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate), amount=VALUES(amount)");
                 $ins->execute([$date, $currency, $val, 1]);
            }
            return $val;
        }
    }
    return null;
}

function resolveRate(PDO $pdo, string $date, string $currency) {
    if ($currency === 'CZK') return 1.0;
    
    // 1. Try DB
    $stmt = $pdo->prepare("SELECT rate, amount FROM rates WHERE currency=? AND date<=? ORDER BY date DESC LIMIT 1");
    $stmt->execute([$currency, $date]);
    $row = $stmt->fetch();
    if ($row) {
        return (float)$row['rate'] / (float)($row['amount']?:1);
    }
    
    // 2. Fetch CNB
    return fetchCnbAndStore($pdo, $date, $currency);
}

/**
 * Save or update ticker mapping info
 * Also detects potential aliases based on company_name or ISIN
 */
function saveTickerMapping(PDO $pdo, array $txData): void {
    $ticker = strtoupper(trim($txData['id'] ?? ''));
    $currency = strtoupper(trim($txData['currency'] ?? ''));
    $isin = trim($txData['isin'] ?? '');
    $companyName = trim($txData['company_name'] ?? '');
    
    // Skip cash, fees, etc.
    if (empty($ticker) || preg_match('/^(CASH_|FEE_|FX_|CORP_ACTION)/', $ticker)) {
        return;
    }
    
    // Only save if we have ISIN or company name
    if (empty($isin) && empty($companyName)) {
        return;
    }
    
    try {
        // First, check if this ticker already exists
        $existingStmt = $pdo->prepare("SELECT ticker, alias_of FROM ticker_mapping WHERE ticker = ?");
        $existingStmt->execute([$ticker]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
        
        // If ticker already has an alias set, don't override
        if ($existing && !empty($existing['alias_of'])) {
            // Just update other fields if needed
            $sql = "UPDATE ticker_mapping SET 
                        company_name = COALESCE(NULLIF(:company, ''), company_name),
                        isin = COALESCE(NULLIF(:isin, ''), isin),
                        currency = COALESCE(NULLIF(:currency, ''), currency),
                        last_verified = NOW()
                    WHERE ticker = :ticker";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':ticker' => $ticker,
                ':company' => $companyName,
                ':isin' => $isin,
                ':currency' => $currency
            ]);
            return;
        }
        
        // Detect potential alias - look for existing ticker with same company name or ISIN
        $aliasOf = null;
        
        // Normalize company name for comparison
        $normalizedName = normalizeCompanyName($companyName);
        
        if (!empty($isin)) {
            // Check by ISIN first (more reliable)
            $isinStmt = $pdo->prepare("
                SELECT ticker FROM ticker_mapping 
                WHERE isin = ? AND ticker != ? AND (alias_of IS NULL OR alias_of = '')
                ORDER BY last_verified DESC 
                LIMIT 1
            ");
            $isinStmt->execute([$isin, $ticker]);
            $match = $isinStmt->fetchColumn();
            if ($match) {
                $aliasOf = $match;
            }
        }
        
        if (!$aliasOf && !empty($normalizedName) && strlen($normalizedName) >= 3) {
            // Check by normalized company name
            $nameStmt = $pdo->prepare("
                SELECT ticker, company_name FROM ticker_mapping 
                WHERE ticker != ? AND (alias_of IS NULL OR alias_of = '')
                AND company_name IS NOT NULL AND company_name != ''
            ");
            $nameStmt->execute([$ticker]);
            $candidates = $nameStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($candidates as $candidate) {
                $candidateNorm = normalizeCompanyName($candidate['company_name']);
                if ($candidateNorm === $normalizedName) {
                    // Exact match after normalization
                    $aliasOf = $candidate['ticker'];
                    break;
                }
                // Partial match - if one contains the other and they're reasonably similar
                if (strlen($candidateNorm) >= 3 && strlen($normalizedName) >= 3) {
                    if (strpos($candidateNorm, $normalizedName) !== false || 
                        strpos($normalizedName, $candidateNorm) !== false) {
                        // Calculate similarity
                        similar_text($candidateNorm, $normalizedName, $percent);
                        if ($percent >= 85) {
                            $aliasOf = $candidate['ticker'];
                            break;
                        }
                    }
                }
            }
        }
        
        // Insert or update
        $sql = "INSERT INTO ticker_mapping 
                    (ticker, company_name, isin, currency, alias_of, status, last_verified)
                VALUES 
                    (:ticker, :company, :isin, :currency, :alias_of, 'needs_review', NOW())
                ON DUPLICATE KEY UPDATE
                    company_name = COALESCE(NULLIF(:company, ''), company_name),
                    isin = COALESCE(NULLIF(:isin, ''), isin),
                    currency = COALESCE(NULLIF(:currency, ''), currency),
                    alias_of = COALESCE(alias_of, :alias_of),
                    last_verified = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':ticker' => $ticker,
            ':company' => $companyName,
            ':isin' => $isin,
            ':currency' => $currency,
            ':alias_of' => $aliasOf
        ]);
        
        if ($aliasOf) {
            error_log("Ticker alias detected: $ticker -> $aliasOf (based on " . 
                      (!empty($isin) ? "ISIN: $isin" : "company: $companyName") . ")");
        }
        
    } catch (Exception $e) {
        // Silent fail - mapping is optional
        error_log("saveTickerMapping failed for $ticker: " . $e->getMessage());
    }
}

/**
 * Normalize company name for comparison
 */
function normalizeCompanyName(string $name): string {
    $name = mb_strtolower(trim($name));
    // Remove common suffixes
    $suffixes = [' inc', ' inc.', ' corp', ' corp.', ' corporation', ' ag', ' se', ' plc', 
                 ' ltd', ' ltd.', ' limited', ' group', ' holdings', ' co', ' co.', ' company', 
                 ' s.a.', ' n.v.', ' class a', ' class b', ' class c', '(class a)', '(class b)',
                 ' - class a', ' - class b', ' common stock', ' ordinary shares'];
    foreach ($suffixes as $s) {
        $name = str_replace($s, '', $name);
    }
    // Remove punctuation
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    // Remove extra spaces
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

/**
 * Ensures the ticker exists in the quotes table with correct metadata
 */
function ensureTickerMeta(PDO $pdo, string $ticker, string $productType) {
    if (empty($ticker)) return;
    if (preg_match('/^(CASH_|FEE_|FX_|CORP_)/', $ticker)) return;
    
    // Determine asset_type (lowercase for DB consistency)
    $assetType = (strcasecmp($productType, 'Crypto') === 0) ? 'crypto' : 'stock';
    
    // Insert or update type
    // We rely on rebuild_table.php having set Primary Key on 'id'
    try {
        $sql = "INSERT INTO live_quotes (id, asset_type, last_fetched, status) 
                VALUES (?, ?, NOW(), 'active')
                ON DUPLICATE KEY UPDATE 
                    asset_type = IF(asset_type IS NULL OR asset_type = 'stock', VALUES(asset_type), asset_type)";
        // Logic: If existing is 'stock' and new is 'crypto', update it. If existing is 'crypto', keep it.
        
        $pdo->prepare($sql)->execute([$ticker, $assetType]);
    } catch (Exception $e) {
        // Silent fail, not critical for import flow
    }
}

/**
 * Automatically adds ticker to user's watchlist during import
 */
function addToWatchlist(PDO $pdo, int $userId, string $ticker) {
    if (empty($ticker) || $userId <= 0) return;
    // Skip special pseudo-tickers
    if (preg_match('/^(CASH_|FEE_|FX_|CORP_)/', $ticker)) return;
    
    try {
        // Ensure watch table exists (in case legacy DB)
        $pdo->exec("CREATE TABLE IF NOT EXISTS watch (
            user_id INT NOT NULL,
            ticker VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, ticker)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Insert or ignore if already exists
        $stmt = $pdo->prepare("INSERT IGNORE INTO watch (user_id, ticker) VALUES (?, ?)");
        $stmt->execute([$userId, $ticker]);
    } catch (Exception $e) {
        // Silent fail, watchlist is not critical
        error_log("addToWatchlist failed for $ticker: " . $e->getMessage());
    }
}

/* ===================== Input ===================== */
/* ===================== Input ===================== */
$inputData = json_decode(file_get_contents('php://input'), true);
file_put_contents('import_debug.log', date('Y-m-d H:i:s'). " Input: " . print_r($inputData, true) . "\n", FILE_APPEND);

if (!$inputData) {
    // Fallback pro testování nebo formulářová data, pokud by se posílala jinak
    if (isset($_POST['rows'])) {
        $inputData = $_POST;
    } else {
        // Pokud je spuštěno naprázdno (GET), vrátíme prázdné pole, aby to nespadlo na Fatal Error,
        // ale skript nic neudělá.
        $rows = [];
        $provider = null;
        // Ale pro API je to chyba
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
             throw new Exception('Žádná data na vstupu (JSON decode failed)');
        }
    }
}

$rows = $inputData['rows'] ?? $inputData['transactions'] ?? [];
$provider = $inputData['provider'] ?? null;

if (!is_array($rows)) {
    // Pokud rows není pole, zkusíme jestli to není přímo list transakcí
    if (isset($inputData[0]['date'])) {
        $rows = $inputData;
    } else {
        $rows = [];
    }
}

/* ===================== Prepared statements ===================== */
try {
    if ($hasFingerprint) {
    $insStmt = $pdo->prepare(
        "INSERT INTO transactions
         (user_id, date, id, amount, price, ex_rate, amount_cur, currency, amount_czk, platform, product_type, trans_type, fees, notes, fingerprint)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $dupFpStmt = $pdo->prepare(
        "SELECT trans_id FROM transactions WHERE user_id=? AND fingerprint=? LIMIT 1"
    );
} else {
    // Fallback dedupe bez fingerprintu
    $insStmt = $pdo->prepare(
        "INSERT INTO transactions
         (user_id, date, id, amount, price, ex_rate, amount_cur, currency, amount_czk, platform, product_type, trans_type, fees, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $dupStmt = $pdo->prepare(
        "SELECT trans_id FROM transactions
         WHERE user_id=? AND date=? AND id=? AND ROUND(amount,8)=ROUND(?,8)
           AND ( (price IS NULL AND ? IS NULL) OR ROUND(price,6)=ROUND(?,6) )
           AND trans_type=? AND platform=? AND currency=?
           AND (ROUND(amount_cur,2)=ROUND(?,2) OR ROUND(amount_czk,2)=ROUND(?,2))
         LIMIT 1"
    );
}

/* ===================== Main loop ===================== */
$inserted=0; $skipped=0; $failed=0; $errors=[];
$skipped_dup=0; $skipped_invalidId=0;
$uniqueTickers = [];

foreach ($rows as $i => $r) {
    try {
        $date        = trim($r['date'] ?? '');
        $id          = strtoupper(trim($r['id'] ?? '')); // normalize
        $amount      = (float)($r['amount']      ?? 0);
        $price       = array_key_exists('price',$r) ? ( $r['price'] === null ? null : (float)$r['price'] ) : null;
        $ex_rate     = array_key_exists('ex_rate',$r) ? ( $r['ex_rate'] === null ? null : (float)$r['ex_rate'] ) : null;
        $amount_cur  = (float)($r['amount_cur']  ?? 0);
        $currency    = strtoupper(trim($r['currency'] ?? 'CZK'));
        $amount_czk  = array_key_exists('amount_czk',$r) ? ( $r['amount_czk'] === null ? null : (float)$r['amount_czk'] ) : null;
        $platform    = trim($r['platform']    ?? ($provider ?: ''));
        $product     = trim($r['product_type']?? '');
        $trans_type  = trim($r['trans_type']  ?? '');
        $fees        = array_key_exists('fees',$r) ? ( $r['fees'] === null ? 0.0 : (float)$r['fees'] ) : 0.0;
        $notes       = trim($r['notes']       ?? ('import: '.$provider));
        $external_id = isset($r['external_id']) ? trim((string)$r['external_id']) : '';

        if (!$date) { $failed++; $errors[]="řádek $i: chybí date"; continue; }

        // Skip garbage IDs – Crypto: povolir i číslo na začátku, ale vyžadovat aspoň 1 písmeno (1INCH ok, 300/914 ne)
        $isCrypto  = strcasecmp($product ?? '', 'Crypto') === 0;
        $allowedId = $isCrypto
          ? (bool)preg_match('/^(?=.*[A-Z])[A-Z0-9][A-Z0-9.\-]{0,19}$/', $id)
          : (bool)preg_match('/^([A-Z][A-Z0-9.\-]{0,19}|CASH_[A-Z]{3}|FEE_[A-Z0-9_]+|CORP_ACTION|FX_[A-Z]+)$/', $id);

        if (!$allowedId && !in_array($product, ['Cash','Fee','FX','Tax'], true)) {
            $skipped++; $skipped_invalidId++;
            $errors[]="řádek $i: nepřijaté ID '$id' (skip)";
            continue;
            continue;
        }

        $uniqueTickers[] = $id;

        // Resolve FX/ex_rate
        if ($currency === 'CZK') { $ex_rate = 1.0; }
        if (!$ex_rate || $ex_rate <= 0) {
            $ex_rate = resolveRate($pdo, $date, $currency) ?? ($currency==='CZK'?1.0:null);
        }

        // amount_czk
        if ($amount_czk === null) {
            if ($currency === 'CZK') $amount_czk = $amount_cur;
            elseif ($ex_rate) $amount_czk = round($amount_cur * $ex_rate, 2);
            else $amount_czk = 0.0;
        }

        // SELL má být příjem => kladné částky (směr určuje trans_type)
        if (strcasecmp($trans_type, 'Sell') === 0) {
            if ($amount_cur < 0)  $amount_cur  = abs($amount_cur);
            if ($amount_czk !== null && $amount_czk < 0) $amount_czk = abs($amount_czk);
            if ($price !== null && $price < 0) $price = abs($price);
        }

        // Fingerprint input
        $txForFp = [
            'date' => $date, 'id' => $id, 'amount' => $amount, 'price' => $price,
            'ex_rate' => $ex_rate, 'amount_cur' => $amount_cur, 'currency' => $currency,
            'amount_czk' => $amount_czk, 'platform' => $platform, 'product_type' => $product,
            'trans_type' => $trans_type, 'fees' => $fees, 'notes' => $notes, 'external_id' => $external_id
        ];
        $fingerprint = $hasFingerprint ? txFingerprint($txForFp) : null;

        // duplicate check
        if ($hasFingerprint) {
            $dupFpStmt->execute([$currentUserId, $fingerprint]);
            if ($dupFpStmt->fetchColumn()) { $skipped++; $skipped_dup++; continue; }
        } else {
            $dupStmt->execute([
                $currentUserId,$date,$id,$amount,
                $price,$price,
                $trans_type,$platform,$currency,
                $amount_cur,$amount_czk
            ]);
            if ($dupStmt->fetchColumn()) { $skipped++; $skipped_dup++; continue; }
        }

        // insert
        if ($hasFingerprint) {
            try {
                $insStmt->execute([
                    $currentUserId,$date,$id,$amount,$price,$ex_rate,$amount_cur,$currency,$amount_czk,
                    $platform,$product,$trans_type,$fees,$notes,$fingerprint
                ]);
                $inserted++;
                
                // Save ticker mapping if we have ISIN or company name
                saveTickerMapping($pdo, $r);
                
                // Auto-register in live quotes with correct type
                ensureTickerMeta($pdo, $id, $product);
                
                // Auto-add to watchlist
                addToWatchlist($pdo, $currentUserId, $id);
            } catch (PDOException $e) {
                if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) { // duplicate key
                    $skipped++; $skipped_dup++;
                    continue;
                }
                throw $e;
            }
        } else {
            $insStmt->execute([
                $currentUserId,$date,$id,$amount,$price,$ex_rate,$amount_cur,$currency,$amount_czk,
                $platform,$product,$trans_type,$fees,$notes
            ]);
            $inserted++;
            
            // Save ticker mapping if we have ISIN or company name
            saveTickerMapping($pdo, $r);
            
            // Auto-register in live quotes with correct type
            ensureTickerMeta($pdo, $id, $product);
            
            // Auto-add to watchlist
            addToWatchlist($pdo, $currentUserId, $id);
        }
    } catch (Exception $e) {
        $failed++; $errors[] = "řádek $i: ".$e->getMessage();
    }
}

// Update prices for new/processed tickers if missing/old
if (!empty($uniqueTickers)) {
    try {
        set_time_limit(300);
        $gService = new GoogleFinanceService($pdo, 3600); // 1h cache
        $todo = array_unique($uniqueTickers);
        $updatedPrices = 0;
        foreach ($todo as $t) {
             if (empty($t) || preg_match('/^(CASH_|FEE_|FX_|CORP_)/', $t)) continue;
             
             // Check if we need update (today's price missing OR price is 0)
             $stmt = $pdo->prepare("SELECT last_fetched, current_price FROM live_quotes WHERE id = ?");
             $stmt->execute([$t]);
             $row = $stmt->fetch(PDO::FETCH_ASSOC);
             
             $last = $row['last_fetched'] ?? null;
             $price = $row['current_price'] ?? null;
             
             // Update if never fetched, OR not today, OR price is null/zero
             if (!$last || substr($last, 0, 10) !== date('Y-m-d') || $price === null || $price == 0) {
                 $gService->getQuote($t, true); // Force fresh
                 $updatedPrices++;
             }
        }
        if ($updatedPrices > 0) {
             $errors[] = "Info: Aktualizovány ceny pro $updatedPrices tickerů.";
        }
    } catch(Exception $e) { error_log("Price update error: ".$e->getMessage()); }
}



echo json_encode([
    'success'=>true,
    'message'=>"Import dokončen. Vloženo: $inserted, přeskočeno (dup/skip): $skipped, chyb: $failed",
    'inserted'=>$inserted,
    'skipped'=>$skipped,
    'failed'=>$failed,
    'errors'=>$errors,
    'user_id'=>$currentUserId,
    'provider'=>$provider,
    'dedupe_mode'=>$hasFingerprint ? 'fingerprint' : 'fallback',
    'skipped_reasons' => [
        'duplicate'  => $skipped_dup,
        'invalid_id' => $skipped_invalidId,
        'other'      => max(0, $skipped - $skipped_dup - $skipped_invalidId),
    ],
]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>'Critical Error: '.$e->getMessage()]);
}