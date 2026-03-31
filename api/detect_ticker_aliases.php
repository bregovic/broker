<?php
/**
 * Script to detect potential ticker aliases based on company_name similarity
 * 
 * It finds tickers with similar company names and suggests linking them.
 * Can be run with ?apply=1 to automatically apply the detected aliases.
 */

$paths = [
    __DIR__ . '/env.local.php',
    __DIR__ . '/env.php',
    __DIR__ . '/../env.php'
];

foreach ($paths as $p) {
    if (file_exists($p)) {
        require_once $p;
        break;
    }
}

if (!defined('DB_HOST')) {
    die("Error: Could not load env config.\n");
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB Connection Error: " . $e->getMessage() . "\n");
}

$applyChanges = isset($_GET['apply']) && $_GET['apply'] === '1';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Ticker Alias Detection ===\n\n";

// Get all tickers with company names from ticker_mapping
$mappings = $pdo->query("
    SELECT ticker, company_name, isin, last_verified, alias_of 
    FROM ticker_mapping 
    WHERE company_name IS NOT NULL AND company_name != ''
    ORDER BY ticker
")->fetchAll(PDO::FETCH_ASSOC);

// Get all used tickers from transactions
$usedTickers = $pdo->query("
    SELECT DISTINCT id as ticker FROM transactions
")->fetchAll(PDO::FETCH_COLUMN);

// Get live_quotes data for additional context
$liveQuotes = $pdo->query("
    SELECT id as ticker, company_name, last_fetched 
    FROM live_quotes 
    WHERE company_name IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

// Build lookup arrays
$mappingByTicker = [];
foreach ($mappings as $m) {
    $mappingByTicker[$m['ticker']] = $m;
}

$liveByTicker = [];
foreach ($liveQuotes as $lq) {
    $liveByTicker[$lq['ticker']] = $lq;
}

// Function to normalize company name for comparison
function normalizeName($name) {
    $name = mb_strtolower(trim($name));
    // Remove common suffixes
    $suffixes = [' inc', ' inc.', ' corp', ' corp.', ' corporation', ' ag', ' se', ' plc', ' ltd', ' ltd.', ' limited', ' group', ' holdings', ' co', ' co.', ' company', ' s.a.', ' n.v.', ' class a', ' class b', ' class c', '(class a)', '(class b)'];
    foreach ($suffixes as $s) {
        $name = str_replace($s, '', $name);
    }
    // Remove punctuation
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
    // Remove extra spaces
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

// Find potential duplicates by company name
$normalizedMap = [];
foreach ($mappings as $m) {
    $norm = normalizeName($m['company_name']);
    if (!isset($normalizedMap[$norm])) {
        $normalizedMap[$norm] = [];
    }
    $normalizedMap[$norm][] = $m['ticker'];
}

// Also check live_quotes for tickers not in mapping
foreach ($liveQuotes as $lq) {
    if (!isset($mappingByTicker[$lq['ticker']]) && $lq['company_name']) {
        $norm = normalizeName($lq['company_name']);
        if (!isset($normalizedMap[$norm])) {
            $normalizedMap[$norm] = [];
        }
        if (!in_array($lq['ticker'], $normalizedMap[$norm])) {
            $normalizedMap[$norm][] = $lq['ticker'];
        }
    }
}

$foundAliases = [];
$appliedCount = 0;

echo "Scanning for duplicate company names...\n\n";

foreach ($normalizedMap as $normName => $tickers) {
    if (count($tickers) > 1) {
        // Potential duplicates found
        echo "--- Potential Match: \"$normName\" ---\n";
        
        $tickerDetails = [];
        foreach ($tickers as $t) {
            $hasTransactions = in_array($t, $usedTickers);
            $hasLiveData = isset($liveByTicker[$t]) && $liveByTicker[$t]['last_fetched'];
            $mapping = $mappingByTicker[$t] ?? null;
            $liveData = $liveByTicker[$t] ?? null;
            
            $lastFetched = null;
            if ($liveData && $liveData['last_fetched']) {
                $lastFetched = $liveData['last_fetched'];
            }
            
            $tickerDetails[] = [
                'ticker' => $t,
                'hasTransactions' => $hasTransactions,
                'hasLiveData' => $hasLiveData,
                'lastFetched' => $lastFetched,
                'companyName' => $mapping['company_name'] ?? ($liveData['company_name'] ?? 'N/A'),
                'existingAlias' => $mapping['alias_of'] ?? null
            ];
            
            $txMark = $hasTransactions ? '✓ TX' : '✗ TX';
            $liveMark = $hasLiveData ? '✓ LIVE' : '✗ LIVE';
            $aliasMark = $mapping['alias_of'] ? " (alias of {$mapping['alias_of']})" : '';
            
            echo "  - $t: $txMark, $liveMark, Last: " . ($lastFetched ?: 'never') . $aliasMark . "\n";
        }
        
        // Determine which ticker should be canonical
        // Priority: 1. Has live data with recent fetch, 2. Has transactions, 3. Shorter ticker
        usort($tickerDetails, function($a, $b) {
            // Already an alias? Put it lower
            if ($a['existingAlias'] && !$b['existingAlias']) return 1;
            if (!$a['existingAlias'] && $b['existingAlias']) return -1;
            
            // Has live data?
            if ($a['hasLiveData'] && !$b['hasLiveData']) return -1;
            if (!$a['hasLiveData'] && $b['hasLiveData']) return 1;
            
            // More recent fetch?
            if ($a['lastFetched'] && $b['lastFetched']) {
                $cmp = strcmp($b['lastFetched'], $a['lastFetched']); // DESC
                if ($cmp !== 0) return $cmp;
            }
            
            // Shorter ticker name (usually the current one)
            return strlen($a['ticker']) - strlen($b['ticker']);
        });
        
        $canonical = $tickerDetails[0]['ticker'];
        
        // Skip if already properly aliased
        $allProperlyAliased = true;
        foreach ($tickerDetails as $i => $td) {
            if ($i === 0) continue; // canonical
            if ($td['existingAlias'] !== $canonical && $td['existingAlias'] === null) {
                $allProperlyAliased = false;
            }
        }
        
        if ($allProperlyAliased && count($tickerDetails) <= 2) {
            echo "  → Already properly configured.\n\n";
            continue;
        }
        
        echo "  → Suggested canonical: $canonical\n";
        
        foreach ($tickerDetails as $i => $td) {
            if ($i === 0) continue; // skip canonical
            if ($td['ticker'] === $canonical) continue;
            if ($td['existingAlias'] === $canonical) {
                echo "  → {$td['ticker']} already aliased to $canonical\n";
                continue;
            }
            
            $foundAliases[] = [
                'old' => $td['ticker'],
                'canonical' => $canonical,
                'companyName' => $td['companyName']
            ];
            
            if ($applyChanges) {
                // First ensure canonical exists in ticker_mapping
                $checkStmt = $pdo->prepare("SELECT ticker FROM ticker_mapping WHERE ticker = ?");
                $checkStmt->execute([$canonical]);
                if (!$checkStmt->fetch()) {
                    // Insert canonical if not exists
                    $insertStmt = $pdo->prepare("INSERT INTO ticker_mapping (ticker, company_name, status) VALUES (?, ?, 'verified')");
                    $insertStmt->execute([$canonical, $tickerDetails[0]['companyName']]);
                    echo "  → Created mapping for $canonical\n";
                }
                
                // Check if old ticker exists
                $checkStmt->execute([$td['ticker']]);
                if ($checkStmt->fetch()) {
                    // Update existing
                    $updateStmt = $pdo->prepare("UPDATE ticker_mapping SET alias_of = ? WHERE ticker = ?");
                    $updateStmt->execute([$canonical, $td['ticker']]);
                } else {
                    // Insert with alias
                    $insertStmt = $pdo->prepare("INSERT INTO ticker_mapping (ticker, company_name, alias_of, status) VALUES (?, ?, ?, 'verified')");
                    $insertStmt->execute([$td['ticker'], $td['companyName'], $canonical]);
                }
                
                echo "  → APPLIED: {$td['ticker']} → $canonical\n";
                $appliedCount++;
            } else {
                echo "  → SUGGESTION: {$td['ticker']} should alias to $canonical\n";
            }
        }
        
        echo "\n";
    }
}

echo "=== Summary ===\n";
echo "Found " . count($foundAliases) . " potential alias(es).\n";

if ($applyChanges) {
    echo "Applied $appliedCount alias(es).\n";
} else {
    if (count($foundAliases) > 0) {
        echo "\nTo apply these changes, run with ?apply=1\n";
    }
}
