<?php
namespace Broker\V3;

use Broker\V3\Import\ImportManager;
use Broker\V3\Import\TransactionDTO;
use Throwable;

// Start session for authentication
session_start();

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

function resolveRate($pdo, string $date, string $currency) {
    // Self-healing: reads the rates table and, when we have no rate for this date
    // yet, auto-fetches it from ČNB (single-currency endpoint) and caches it.
    require_once __DIR__ . '/../rate_sync.php';
    $r = ensure_rate($pdo, $currency, $date);
    return $r ?? 1.0;
}

// Error reporting only for debugging phase
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    require_once 'db.php';
    require_once 'Import/TransactionDTO.php';
    require_once 'Import/AbstractParser.php';
    require_once 'Import/ImportManager.php';

    $db = DB::connect();
    $manager = new ImportManager($db);
    $action = $_GET['action'] ?? 'process'; 

    $userId = resolveUserId();
    // `list_rules` bývalo z kontroly vyjmuté, takže kdokoli zvenku viděl seznam
    // importních pravidel včetně tříd parserů. Přihlášení teď platí pro všechny akce.
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Uživatel není přihlášen. Přihlaste se prosím znovu.']);
        exit;
    }

    // 0. LIST RULES (For dropdowns)
    if ($action === 'list_rules') {
        echo json_encode(['success' => true, 'rules' => $manager->getAvailableRules()], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // 1. ANALYZE (Stage 1) - Multiple files or single re-analyze
    if ($action === 'analyze') {
        $tempFileParam = $_GET['temp_file'] ?? $_POST['temp_file'] ?? null;
        $ruleIdParam = $_GET['rule_id'] ?? $_POST['rule_id'] ?? null;
        
        // Fix for empty string being passed as UUID
        if ($tempFileParam === '') $tempFileParam = null;

        // AUTO-CREATE staging table
        $db->exec("CREATE TABLE IF NOT EXISTS import_staging (
            staging_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
            filename TEXT NOT NULL,
            file_content BYTEA NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        )");

        // Case A: Re-analyze existing staging file
        if ($tempFileParam) {
            $stmt = $db->prepare("SELECT * FROM import_staging WHERE staging_id = ?");
            $stmt->execute([$tempFileParam]);
            $stagingFile = $stmt->fetch();

            if (!$stagingFile) {
                echo json_encode(['success' => false, 'message' => 'Staging file not found: ' . $tempFileParam]);
                exit;
            }

            // Write to a temporary file for the manager to analyze
            $tmpPath = tempnam(sys_get_temp_dir(), 'import_');
            file_put_contents($tmpPath, $stagingFile['file_content']);
            
            $details = $manager->analyzeFile($tmpPath, $stagingFile['filename'], $ruleIdParam);
            unlink($tmpPath);

            echo json_encode([
                'success' => true,
                'data' => [array_merge([
                    'filename' => $stagingFile['filename'],
                    'temp_file' => $tempFileParam,
                    'success' => true
                ], $details)]
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        // Case B: Upload and analyze new files
        if (empty($_FILES)) {
            echo json_encode([
                'success' => false, 
                'message' => 'Nebyly zaslány žádné soubory (prázdné $_FILES).',
                'debug' => ['post_data' => array_keys($_POST), 'server' => $_SERVER['REQUEST_METHOD']]
            ]);
            exit;
        }

        $results = [];
        $filesArr = [];
        foreach ($_FILES as $inputName => $info) {
            if (is_array($info['name'])) {
                foreach ($info['name'] as $i => $name) {
                    $filesArr[] = [
                        'name' => $name,
                        'tmp_name' => $info['tmp_name'][$i],
                        'error' => $info['error'][$i],
                        'size' => $info['size'][$i]
                    ];
                }
            } else {
                $filesArr[] = $info;
            }
        }

        foreach ($filesArr as $file) {
            $analysis = [
                'filename' => $file['name'],
                'extension' => strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)),
                'broker' => 'Neznámý',
                'parser' => 'Neznámý',
                'parser_class' => null,
                'tx_count' => 0,
                'rule_id' => null,
                'asset_type' => 'Neznámý',
                'temp_file' => '',
                'success' => false,
                'error' => null
            ];

            try {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new \Exception("Chyba uploadu: " . ($file['error'] ?? 'unknown'));
                }

                $content = file_get_contents($file['tmp_name']);
                if ($content === false) throw new \Exception("Nelze přečíst tmp soubor: " . $file['tmp_name']);

                $stmt = $db->prepare("INSERT INTO import_staging (filename, file_content) VALUES (?, ?) RETURNING staging_id");
                $stmt->bindValue(1, $file['name'], \PDO::PARAM_STR);
                $stmt->bindValue(2, $content, \PDO::PARAM_LOB);
                $stmt->execute();
                $stagingId = $stmt->fetchColumn();

                $details = $manager->analyzeFile($file['tmp_name'], $file['name']);
                $analysis = array_merge($analysis, $details);
                $analysis['temp_file'] = $stagingId;
                $analysis['success'] = true;
                
            } catch (Throwable $e) {
                $analysis['error'] = $e->getMessage();
            }
            $results[] = $analysis;
        }

        echo json_encode(['success' => true, 'data' => $results], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // 2. IMPORT (Stage 2) - Final Execution
    if ($action === 'import') {
        $input = json_decode(file_get_contents('php://input'), true);
        $items = $input['items'] ?? []; 

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Žádné položky k importu.']);
            exit;
        }

        $summary = [];
        $tickersToWatch = [];
        
        // 2.1 Schema introspection for transactions table to support legacy vs v3 schemas dynamically
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $existingCols = [];
        try {
            if ($driver === 'pgsql') {
                $stmtCols = $db->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'transactions'");
                $existingCols = $stmtCols->fetchAll(\PDO::FETCH_COLUMN);
            } else {
                $stmtCols = $db->query("DESCRIBE transactions");
                $existingCols = $stmtCols->fetchAll(\PDO::FETCH_COLUMN);
            }
        } catch (Throwable $colEx) {
            // Safe fallback schema
            $existingCols = [
                'rec_id', 'ticker', 'transaction_date', 'type', 'quantity', 'price_per_unit',
                'currency', 'fee', 'total_amount', 'source_broker', 'broker_trade_id', 'metadata', 'created_at'
            ];
        }
        $existingCols = array_map('strtolower', $existingCols);

        $db->beginTransaction();
        
        try {
            foreach ($items as $item) {
                if (empty($item['temp_file']) || strlen($item['temp_file']) !== 36) {
                    continue;
                }
                // FETCH FROM POSTGRES STAGING
                $stmt = $db->prepare("SELECT filename, file_content FROM import_staging WHERE staging_id = ?");
                $stmt->execute([$item['temp_file']]);
                $row = $stmt->fetch();
                
                if (!$row) continue;

                // Write to temp file for pdftotext/parsing
                $tempPath = sys_get_temp_dir() . '/imp_' . $item['temp_file'];
                file_put_contents($tempPath, $row['file_content']);

                $ruleId = isset($item['rule_id']) ? (int)$item['rule_id'] : null;
                $result = $manager->processFile($tempPath, $row['filename'], $ruleId);
                $transactions = $result['transactions'];

                $inserted = 0;
                $skipped = 0;
                 foreach ($transactions as $t) {
                    $data = $t->toArray();
                    
                    // Resolve FX/ex_rate and amount_czk
                    $exRate = resolveRate($db, $data['transaction_date'], $data['currency']);
                    $amountCzk = round($data['total_amount'] * $exRate, 2);
                    
                    // Guess product type
                    $productType = (strcasecmp($data['currency'] ?? '', 'USD') === 0 || strcasecmp($data['currency'] ?? '', 'EUR') === 0 || strcasecmp($data['currency'] ?? '', 'GBP') === 0 || strcasecmp($data['currency'] ?? '', 'CZK') === 0) ? 'Stock' : 'Crypto';
                    if (strpos(strtolower($result['parser'] ?? ''), 'crypto') !== false) {
                        $productType = 'Crypto';
                    }
                    
                    // SELL normalizes amounts to positive in trans_type Sell
                    $amountCur = (float)$data['total_amount'];
                    $qty = (float)$data['quantity'];
                    $price = (float)$data['price_per_unit'];
                    if (strcasecmp($data['type'], 'Sell') === 0) {
                        $amountCur = abs($amountCur);
                        $qty = abs($qty);
                        $price = abs($price);
                    }
                    
                    /*
                     * Hotovostní pohyby (vklad, výběr, poplatek, daň) se neváží
                     * k žádnému papíru, takže parsery u nich ticker nevyplňují —
                     * jenže sloupec `ticker` je v databázi NOT NULL a celý import
                     * na tom spadl s "null value in column ticker".
                     *
                     * Doplníme zástupný kód podle měny (konvence `CASH_CZK`, kterou
                     * už zná import-handler) a hlavně srovnáme `product_type`:
                     * Cash/Fee/Tax api-portfolio.php přeskakuje, takže se z vkladu
                     * nestane fiktivní pozice v portfoliu.
                     */
                    $typUpper = strtoupper(trim((string)($data['type'] ?? '')));
                    $menaKod = strtoupper(trim((string)($data['currency'] ?? 'CZK'))) ?: 'CZK';
                    if (trim((string)($data['ticker'] ?? '')) === '') {
                        $zastupne = [
                            'DEPOSIT'    => ['CASH_' . $menaKod, 'Cash'],
                            'WITHDRAWAL' => ['CASH_' . $menaKod, 'Cash'],
                            'FEE'        => ['FEE_' . $menaKod,  'Fee'],
                            'TAX'        => ['TAX_' . $menaKod,  'Tax'],
                            // Přesun peněz mezi vlastními účty u téhož brokera.
                            // Do cashflow ani do portfolia nepatří, ale bez něj
                            // nejde dopočítat pořizovací cenu převedené pozice.
                            'INTERNAL'   => ['CASH_' . $menaKod, 'Internal'],
                        ];
                        if (isset($zastupne[$typUpper])) {
                            [$data['ticker'], $productType] = $zastupne[$typUpper];
                        } else {
                            // Papírová transakce bez tickeru by v portfoliu nadělala
                            // víc škody než užitku — radši ji přeskočit a spočítat.
                            $skipped++;
                            continue;
                        }
                    } elseif (isset(['DEPOSIT' => 1, 'WITHDRAWAL' => 1][$typUpper])) {
                        $productType = 'Cash';
                    } elseif ($typUpper === 'FEE') {
                        $productType = 'Fee';
                    }

                    // Prevent double json encoding of metadata
                    $metadataVal = is_string($data['metadata']) ? $data['metadata'] : json_encode($data['metadata']);

                    // Map possible values to table columns
                    $columnMapping = [
                        // Legacy columns
                        'user_id' => $userId,
                        'date' => $data['transaction_date'],
                        'id' => $data['ticker'],
                        'amount' => $qty,
                        'price' => $price,
                        'ex_rate' => $exRate,
                        'amount_cur' => $amountCur,
                        'currency' => $data['currency'],
                        'amount_czk' => $amountCzk,
                        'platform' => $data['source_broker'],
                        'product_type' => $productType,
                        'trans_type' => $data['type'],
                        'fees' => $data['fee'] ?? 0.0,
                        'notes' => 'import: ' . $data['source_broker'],
                        'fingerprint' => $data['broker_trade_id'],
                        
                        // New columns
                        'ticker' => $data['ticker'],
                        'transaction_date' => $data['transaction_date'],
                        'type' => $data['type'],
                        'quantity' => $data['quantity'],
                        'price_per_unit' => $data['price_per_unit'],
                        'fee' => $data['fee'],
                        'total_amount' => $data['total_amount'],
                        'source_broker' => $data['source_broker'],
                        'broker_trade_id' => $data['broker_trade_id'],
                        'metadata' => $metadataVal
                    ];
                    
                    $insertCols = [];
                    $insertVals = [];
                    foreach ($columnMapping as $col => $val) {
                        if (in_array(strtolower($col), $existingCols)) {
                            $insertCols[] = $col;
                            $insertVals[] = $val;
                        }
                    }
                    
                    if (empty($insertCols)) {
                        continue;
                    }
                    
                    $colsStr = implode(', ', $insertCols);
                    $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                    
                    /*
                     * Duplicita se musí posuzovat POUZE v rámci uživatele.
                     * Dřív tu bylo ON CONFLICT (broker_trade_id), jenže číslo
                     * obchodu přiděluje broker, ne naše aplikace — takže jakmile
                     * měl obchod stejné číslo jako obchod jiného uživatele,
                     * vložení se tiše přeskočilo. Na novém účtu se tím pádem
                     * jako duplicita vyhodnotilo úplně všechno.
                     *
                     * Složený unikátní index (user_id, broker_trade_id) nemusí
                     * v databázi existovat, proto se na ON CONFLICT nespoléháme
                     * a duplicitu kontrolujeme dotazem omezeným na uživatele.
                     */
                    $conflictClause = "";

                    if (in_array('broker_trade_id', $existingCols) && !empty($data['broker_trade_id'])) {
                        $stmtDup = $db->prepare(
                            "SELECT 1 FROM transactions WHERE user_id = ? AND broker_trade_id = ? LIMIT 1"
                        );
                        $stmtDup->execute([$userId, $data['broker_trade_id']]);
                        if ($stmtDup->fetchColumn()) {
                            $skipped++;
                            continue;
                        }
                    }
                    
                    $sql = "INSERT INTO transactions ($colsStr) VALUES ($placeholders)" . $conflictClause;
                    
                    $stmtIns = $db->prepare($sql);

                    /*
                     * Jedna kolize nesmí shodit celý import. Když databáze
                     * odmítne řádek kvůli unikátnímu indexu (23505), bereme to
                     * jako duplicitu a pokračujeme dál — dřív z toho byla
                     * "KRITICKÁ CHYBA" a nenahrálo se vůbec nic.
                     */
                    /*
                     * Bod obnovy kolem každého řádku. V Postgresu shodí jediný
                     * neúspěšný příkaz celou transakci ("current transaction is
                     * aborted") a všechno další už jen padá. Se SAVEPOINT se
                     * vrátíme jen o ten jeden řádek zpět a dávka pokračuje.
                     */
                    $db->exec('SAVEPOINT radek');
                    try {
                        $stmtIns->execute($insertVals);
                        $db->exec('RELEASE SAVEPOINT radek');
                    } catch (\PDOException $e) {
                        $db->exec('ROLLBACK TO SAVEPOINT radek');
                        if (($e->getCode() ?? '') === '23505') { $skipped++; continue; }
                        throw $e;
                    }

                    if ($stmtIns->rowCount() > 0) {
                        $inserted++;
                        if (!empty($data['ticker'])) {
                            $tickersToWatch[] = $data['ticker'];
                        }
                    }
                    else $skipped++;
                }

                $summary[] = [
                    'filename' => $row['filename'],
                    'parser' => $result['parser'],
                    'found' => count($transactions),
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                    'success' => true
                ];
                
                // Cleanup
                @unlink($tempPath);
                $db->prepare("DELETE FROM import_staging WHERE staging_id = ?")->execute([$item['temp_file']]);
            }

            $db->commit();
            
            // Auto-add tickers to watchlist safely AFTER commit (avoids aborting main transaction on watch insert failures)
            if (!empty($tickersToWatch)) {
                $tickersToWatch = array_unique($tickersToWatch);
                foreach ($tickersToWatch as $ticker) {
                    try {
                        $db->prepare("INSERT INTO watch (user_id, ticker) VALUES (?, ?) ON CONFLICT DO NOTHING")
                           ->execute([$userId, $ticker]);
                    } catch (\Exception $e) {}

                    /*
                     * Zaregistrovat ticker v live_quotes. Tenhle import to nikdy
                     * nedělal, a protože `ajax-fetch-history.php` umí jen UPDATE,
                     * neexistující řádek znamenal, že se cena nemá kam zapsat —
                     * nově naimportované papíry (Fio, krypto) proto zůstaly navždy
                     * bez ceny a portfolio bez celkové hodnoty.
                     * Cenu nevyplňujeme, jen založíme řádek; naplní ho fetch.
                     */
                    try {
                        $db->prepare(
                            "INSERT INTO live_quotes (ticker, price, currency, status)
                             VALUES (?, 0, '', 'active')
                             ON CONFLICT (ticker) DO NOTHING"
                        )->execute([$ticker]);
                    } catch (\Exception $e) {}
                }
            }
            
            /*
             * Převedené pozice (např. BTC z Coinbase Pro) přišly bez pořizovací
             * ceny. Ta se dá odvodit až z celé historie účtu, ne z jednoho
             * souboru, takže se dopočítává tady — po commitu, nad vším, co
             * uživatel do téhle chvíle naimportoval.
             */
            $zaklady = [];
            try {
                require_once __DIR__ . '/cost_basis.php';
                $zaklady = \dopocitat_porizovaci_ceny($db, $userId);
            } catch (Throwable $e) {
                $zaklady = ['chyba' => $e->getMessage()];
            }

            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'porizovaci_ceny' => $zaklady,
            ], JSON_INVALID_UTF8_SUBSTITUTE);

        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Neznámá akce nebo chybějící data.']);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'KRITICKÁ CHYBA: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
