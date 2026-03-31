<?php

/**
 * GoogleFinanceService
 *
 * - načítá aktuální cenu z Google Finance (scraping)
 * - ukládá do tabulky live_quotes
 * - vrací asociativní pole s daty
 *
 * Použití:
 *   require_once __DIR__ . '/googlefinanceservice.php';
 *   $service = new GoogleFinanceService($pdo, 0);
 *   $data = $service->getQuote('AAPL', true);
 *
 *   V2.1 - Yahoo Fallback added
 */
class GoogleFinanceService
{
    /** @var PDO */
    private $pdo;

    /** @var int TTL v sekundách; 0 = jen dnešní záznam */
    private $ttlSeconds;

    public function __construct(PDO $pdo, int $ttlSeconds = 0)
    {
        $this->pdo = $pdo;
        $this->ttlSeconds = $ttlSeconds;
    }

    private function fetchUrl($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    /**
     * Vrátí data pro ticker.
     * @param string $ticker
     * @param bool   $forceFresh  true = vždy natáhne z webu a uloží do DB
     * @param string|null $targetCurrency
     * @param string|null $assetType explicit asset type (stock/crypto)
     * @return array|null
     */
    public function getQuote(string $ticker, bool $forceFresh = false, ?string $targetCurrency = null, ?string $assetType = null): ?array
    {
        $ticker = strtoupper(trim($ticker));
        if ($ticker === '') {
            throw new InvalidArgumentException('Ticker is empty');
        }

        // Check if this ticker is an alias for another ticker
        $canonicalTicker = $this->resolveAlias($ticker);
        $isAlias = ($canonicalTicker !== $ticker);
        $fetchTicker = $canonicalTicker; // Use canonical for fetching

        if (!$forceFresh) {
            // Try cache for both original and canonical
            $cached = $this->getCachedQuote($ticker);
            if ($cached !== null) {
                if ($this->validateAgainstMapping($ticker, $cached['company_name'])) {
                    return $cached;
                }
            }
            
            // If alias, also check canonical cache
            if ($isAlias) {
                $cachedCanonical = $this->getCachedQuote($canonicalTicker);
                if ($cachedCanonical !== null) {
                    // Return with original ticker ID
                    $cachedCanonical['ticker'] = $ticker;
                    $cachedCanonical['_resolved_from'] = $canonicalTicker;
                    return $cachedCanonical;
                }
            }
        }

        $data = $this->fetchFromGoogleFinance($fetchTicker, $targetCurrency, $assetType);

        // Fallback: Zkusíme prohodit tečku a pomlčku (např. BRK.B <-> BRK-B)
        if ($data === null) {
            $altTicker = null;
            if (strpos($fetchTicker, '.') !== false) {
                $altTicker = str_replace('.', '-', $fetchTicker);
            } elseif (strpos($fetchTicker, '-') !== false) {
                $altTicker = str_replace('-', '.', $fetchTicker);
            }

            if ($altTicker) {
                $dataAlt = $this->fetchFromGoogleFinance($altTicker, $targetCurrency, $assetType);
                if ($dataAlt !== null) {
                    $data = $dataAlt;
                    $data['ticker'] = $fetchTicker; 
                }
            }
        }
        
        if ($data === null) {
            return null;
        }

        // Set the ticker to original request (not canonical)
        $data['ticker'] = $ticker;
        if ($isAlias) {
            $data['_resolved_from'] = $canonicalTicker;
        }

        // Validate before saving
        if (!$this->validateAgainstMapping($ticker, $data['company_name'])) {
            return null;
        }

        // Save under original ticker
        $this->saveQuote($ticker, $data);
        
        // Also save under canonical if it's an alias
        if ($isAlias) {
            $canonicalData = $data;
            $canonicalData['ticker'] = $canonicalTicker;
            $this->saveQuote($canonicalTicker, $canonicalData);
        }

        return $data;
    }

    /**
     * Resolve ticker alias to canonical ticker
     * Returns the canonical ticker, or the input ticker if no alias exists
     */
    private function resolveAlias(string $ticker): string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT alias_of FROM ticker_mapping WHERE ticker = ? AND alias_of IS NOT NULL LIMIT 1");
            $stmt->execute([$ticker]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row && !empty($row['alias_of'])) {
                return strtoupper($row['alias_of']);
            }
        } catch (Exception $e) {
            // Column might not exist yet, ignore
        }
        
        return $ticker;
    }

    /**
     * Validate if the fetched company name matches the mapping (if exists)
     */
    private function validateAgainstMapping(string $ticker, ?string $fetchedName): bool
    {
        if (empty($fetchedName)) return true; // Cannot validate if no name

        // Get mapping
        $stmt = $this->pdo->prepare("SELECT company_name FROM ticker_mapping WHERE ticker = ? LIMIT 1");
        $stmt->execute([$ticker]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mapping || empty($mapping['company_name'])) {
            return true; // No mapping, assume it's correct
        }

        $mappedName = $mapping['company_name'];
        
        // Normalize names for comparison
        $n1 = mb_strtolower(trim($mappedName));
        $n2 = mb_strtolower(trim($fetchedName));
        
        // Remove common suffixes
        $suffixes = [' inc', ' corp', ' ag', ' se', ' plc', ' ltd', ' s.a.', ' corporation', ' incorporated', ' limited', ' group', ' holdings'];
        foreach ($suffixes as $s) {
            $n1 = str_replace($s, '', $n1);
            $n2 = str_replace($s, '', $n2);
        }
        
        // 1. Exact match (after cleanup)
        if ($n1 === $n2) return true;
        
        // 2. Containment
        if (strpos($n1, $n2) !== false || strpos($n2, $n1) !== false) return true;
        
        // 3. Similarity
        $sim = 0;
        similar_text($n1, $n2, $sim);
        
        // If similarity is low, reject
        if ($sim < 40) {
            return false;
        }

        return true;
    }

    /**
     * Načte z DB záznam v rámci TTL (nebo dnešní, pokud je TTL=0).
     */
    private function getCachedQuote(string $ticker): ?array
    {
        if ($this->ttlSeconds === 0) {
            $sql = "
                SELECT id          AS ticker,
                       current_price,
                       change_percent,
                       company_name,
                       exchange,
                       currency,
                       last_fetched
                FROM live_quotes
                WHERE id = :ticker
                  AND status = 'active'
                  AND current_price IS NOT NULL
                  AND DATE(last_fetched) = CURRENT_DATE()
                ORDER BY last_fetched DESC
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
        } else {
            $sql = "
                SELECT id          AS ticker,
                       current_price,
                       change_percent,
                       company_name,
                       exchange,
                       currency,
                       last_fetched
                FROM live_quotes
                WHERE id = :ticker
                  AND status = 'active'
                  AND current_price IS NOT NULL
                  AND last_fetched >= (NOW() - INTERVAL :ttl SECOND)
                ORDER BY last_fetched DESC
                LIMIT 1
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':ttl', $this->ttlSeconds, PDO::PARAM_INT);
        }

        $stmt->bindValue(':ticker', $ticker, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'ticker'         => $row['ticker'],
            'current_price'  => (float)$row['current_price'],
            'change_percent' => $row['change_percent'] !== null ? (float)$row['change_percent'] : null,
            'company_name'   => $row['company_name'],
            'exchange'       => $row['exchange'],
            'currency'       => $row['currency'],
            'last_fetched'   => $row['last_fetched'],
        ];
    }

    /**
     * Uloží/aktualizuje záznam v live_quotes.
     */
    public function saveQuote($id, $data) {
        // Explicitně zkontrolujeme existenci, abychom se vyhnuli problémům s duplicitami
        // pokud id není Primary Key (což by mělo být opraveno rebuildem, ale jistota je jistota)
        $stmtCheck = $this->pdo->prepare("SELECT COUNT(*) FROM live_quotes WHERE id = ?");
        $stmtCheck->execute([$id]);
        $exists = $stmtCheck->fetchColumn() > 0;
        
        // Zpracování hodnot
        $currentPrice = $this->parseNumber($data['current_price'] ?? null);
        $changeAmount = $this->parseNumber($data['change_amount'] ?? null);
        $changePercent = $this->parseNumber($data['change_percent'] ?? null);
        
        // Check if we have transactions with a different currency - transactions are source of truth
        $fetchedCurrency = $data['currency'] ?? 'USD';
        $finalCurrency = $fetchedCurrency;
        
        try {
            $stmtTx = $this->pdo->prepare("SELECT currency FROM transactions WHERE id = ? LIMIT 1");
            $stmtTx->execute([$id]);
            $txCurrency = $stmtTx->fetchColumn();
            
            if ($txCurrency && $txCurrency !== $fetchedCurrency) {
                // Transaction currency takes priority
                error_log("Currency override for $id: fetched $fetchedCurrency, using transaction currency $txCurrency");
                $finalCurrency = $txCurrency;
            }
        } catch (Exception $e) {
            // Ignore - might be a new ticker without transactions
        }
        
        $params = [
            $currentPrice,
            $changeAmount,
            $changePercent,
            $finalCurrency,
            $data['exchange'] ?? null,
            $data['company_name'] ?? null,
            $data['source'] ?? 'google_scrape',
            $id
        ];
        
        if ($exists) {
            // UPDATE
            $sql = "UPDATE live_quotes SET 
                    last_fetched = NOW(),
                    current_price = ?,
                    change_amount = ?,
                    change_percent = ?,
                    currency = ?,
                    exchange = ?,
                    company_name = ?,
                    source = ?
                    WHERE id = ?";
        } else {
            // INSERT
            // Tady přidáme id na začátek params pro insert
            array_unshift($params, $id); // params: [id, price, change_am, ...]
            array_pop($params); // id už tam bylo na konci pro update, vyhodíme ho
            // Oprava parametrů pro INSERT: [id, source, last_fetched, curr, prev, ...]
            
            $source = $data['source'] ?? 'google_scrape';
            
            $sql = "INSERT INTO live_quotes 
                    (id, source, last_fetched, current_price, change_amount, change_percent, currency, exchange, company_name, status)
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, 'active')";
                    
            $params = [
                $id,
                $source,
                $currentPrice,
                $changeAmount,
                $changePercent,
                $data['currency'] ?? 'USD',
                $data['exchange'] ?? null,
                $data['company_name'] ?? null
            ];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        // Also save to History as a snapshot (User Request)
        // This ensures today's live price is recorded in history immediately.
        // It will be refined by Yahoo history fetch later if valid.
        if ($currentPrice !== null && $currentPrice > 0) {
            $sqlHist = "INSERT INTO tickers_history (ticker, date, price, source) 
                        VALUES (?, CURDATE(), ?, 'google_live') 
                        ON DUPLICATE KEY UPDATE price=VALUES(price), source=VALUES(source)";
            $stmtH = $this->pdo->prepare($sqlHist);
            $stmtH->execute([$id, $currentPrice]);
        }
        
        // Update ticker_mapping and detect aliases based on company_name
        $companyName = $data['company_name'] ?? null;
        $currency = $data['currency'] ?? null;
        if ($companyName) {
            $this->updateTickerMappingAndDetectAlias($id, $companyName, $currency);
        }
    }

    /**
     * Updates ticker_mapping with company_name and detects potential aliases
     */
    private function updateTickerMappingAndDetectAlias(string $ticker, string $companyName, ?string $currency): void
    {
        try {
            // Normalize company name for comparison
            $normalizedName = $this->normalizeCompanyName($companyName);
            
            // Check if this ticker already exists in mapping
            $stmt = $this->pdo->prepare("SELECT ticker, alias_of, company_name FROM ticker_mapping WHERE ticker = ?");
            $stmt->execute([$ticker]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If already has an alias, just update company_name
            if ($existing && !empty($existing['alias_of'])) {
                $this->pdo->prepare("UPDATE ticker_mapping SET company_name = COALESCE(NULLIF(?, ''), company_name), currency = COALESCE(NULLIF(?, ''), currency), last_verified = NOW() WHERE ticker = ?")
                    ->execute([$companyName, $currency, $ticker]);
                return;
            }
            
            // Look for existing tickers with same normalized company name
            $aliasOf = null;
            
            if (strlen($normalizedName) >= 3) {
                $stmt = $this->pdo->prepare("
                    SELECT ticker, company_name FROM ticker_mapping 
                    WHERE ticker != ? 
                    AND (alias_of IS NULL OR alias_of = '')
                    AND company_name IS NOT NULL AND company_name != ''
                ");
                $stmt->execute([$ticker]);
                $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($candidates as $candidate) {
                    $candidateNorm = $this->normalizeCompanyName($candidate['company_name']);
                    
                    // Exact match after normalization
                    if ($candidateNorm === $normalizedName) {
                        $aliasOf = $candidate['ticker'];
                        break;
                    }
                    
                    // High similarity match
                    if (strlen($candidateNorm) >= 3) {
                        similar_text($candidateNorm, $normalizedName, $percent);
                        if ($percent >= 85) {
                            $aliasOf = $candidate['ticker'];
                            break;
                        }
                    }
                }
            }
            
            // Insert or update ticker_mapping
            $sql = "INSERT INTO ticker_mapping (ticker, company_name, currency, alias_of, status, last_verified)
                    VALUES (?, ?, ?, ?, 'verified', NOW())
                    ON DUPLICATE KEY UPDATE 
                        company_name = COALESCE(NULLIF(VALUES(company_name), ''), company_name),
                        currency = COALESCE(NULLIF(VALUES(currency), ''), currency),
                        alias_of = COALESCE(alias_of, VALUES(alias_of)),
                        last_verified = NOW()";
            
            $this->pdo->prepare($sql)->execute([$ticker, $companyName, $currency, $aliasOf]);
            
            if ($aliasOf) {
                error_log("GoogleFinanceService: Detected alias $ticker -> $aliasOf (company: $companyName)");
            }
            
        } catch (Exception $e) {
            // Column might not exist yet, silently ignore
            error_log("updateTickerMappingAndDetectAlias error: " . $e->getMessage());
        }
    }

    /**
     * Normalize company name for comparison
     */
    private function normalizeCompanyName(string $name): string
    {
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
     * Parses a number string, handling null and empty strings.
     * @param mixed $value
     * @return float|null
     */
    private function parseNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float)$value;
    }

    /**
     * Stáhne data z Google Finance (stocks) nebo CoinGecko (crypto).
     */
    private function fetchFromGoogleFinance(string $ticker, ?string $targetCurrency = null, ?string $assetType = null): ?array
    {
        // Fix for CBK collision: Revolut exports Commerzbank as 'CBK'.
        // Yahoo/Google defaults 'CBK' to Commercial BancGroup (US/USD).
        // For Revolut users, CBK is ALWAYS Commerzbank AG (Germany/EUR).
        if ($ticker === 'CBK') {
            $ticker = 'CBK.DE';
        }

        // Detect crypto tickers and use CoinGecko API
        $cryptoTickers = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'ADA' => 'cardano',
            'DOT' => 'polkadot',
            'SOL' => 'solana',
            'MATIC' => 'matic-network',
            'AVAX' => 'avalanche-2',
            'LINK' => 'chainlink',
            'UNI' => 'uniswap',
            'ATOM' => 'cosmos',
            'XRP' => 'ripple',
            'LTC' => 'litecoin',
            'BCH' => 'bitcoin-cash',
            'DOGE' => 'dogecoin',
            'SHIB' => 'shiba-inu'
        ];

        // Use heuristic if explicit type not provided
        $isCrypto = ($assetType === 'crypto') || isset($cryptoTickers[$ticker]);

        // For crypto, try Yahoo Finance first, then CoinGecko
        if ($isCrypto) {
            // 1. Yahoo Finance (BTC-USD)
            try {
                $yahooTicker = $ticker . '-USD';
                $urlY = "https://query1.finance.yahoo.com/v8/finance/chart/{$yahooTicker}?interval=1d&range=1d";
                
                $jsonY = $this->fetchUrl($urlY);
                
                if ($jsonY) {
                    $dataY = json_decode($jsonY, true);
                    $result = $dataY['chart']['result'][0] ?? null;
                    if ($result && isset($result['meta']['regularMarketPrice'])) {
                        $price = (float)$result['meta']['regularMarketPrice'];
                        $changePct = null;
                        
                        // Calculate change percent from previous close
                        if (isset($result['meta']['chartPreviousClose']) && $result['meta']['chartPreviousClose'] > 0) {
                            $prev = (float)$result['meta']['chartPreviousClose'];
                            $changePct = (($price - $prev) / $prev) * 100;
                        }

                        return [
                            'ticker'         => $ticker,
                            'current_price'  => $price,
                            'change_percent' => $changePct, 
                            'company_name'   => $ticker . ' (Crypto)',
                            'exchange'       => 'CRYPTO',
                            'currency'       => 'USD',
                            'source'         => 'yahoo',
                        ];
                    }
                }
            } catch (Exception $e) { /* ignore */ }

            // 2. CoinGecko Fallback
            $coinGeckoId = $cryptoTickers[$ticker] ?? strtolower($ticker); // Fallback to ticker name if not in map
            $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . urlencode($coinGeckoId) . '&vs_currencies=usd&include_24hr_change=true';
            
            $response = $this->fetchUrl($url);
            if ($response !== false && $response !== '') {
                $apiData = json_decode($response, true);
                if (isset($apiData[$coinGeckoId]['usd'])) {
                    return [
                        'ticker'         => $ticker,
                        'current_price'  => (float)$apiData[$coinGeckoId]['usd'],
                        'change_percent' => (float)($apiData[$coinGeckoId]['usd_24h_change'] ?? 0),
                        'company_name'   => $ticker,
                        'exchange'       => 'CRYPTO',
                        'currency'       => 'USD',
                        'source'         => 'coingecko',
                    ];
                }
            }
            
            return null;
        }

        // For stocks, try Yahoo Finance FIRST (API is more reliable than scraping)
        $yahooData = $this->fetchFromYahoo($ticker);
        if ($yahooData !== null) {
            return $yahooData;
        }

        // Fallback: Google Finance Scraper
        // Select exchanges based on target currency (like old bal.php system)
        $exchanges = [];
        switch ($targetCurrency) {
            case 'EUR':
                $exchanges = ['ETR', 'FRA', 'XETRA', 'AMS', 'VIE', 'BIT'];
                break;
            case 'GBP':
                $exchanges = ['LON'];
                break;
            case 'CAD':
                $exchanges = ['TSE', 'CVE'];
                break;
            case 'JPY':
                $exchanges = ['TYO'];
                break;
            case 'AUD':
                $exchanges = ['ASX'];
                break;
            case 'HKD':
                $exchanges = ['HKG'];
                break;
            case 'USD':
            default:
                $exchanges = ['NASDAQ', 'NYSE', 'NYSEARCA', 'NYSEAMERICAN'];
                break;
        }
        
        // Add specific exchanges for ETFs often traded in Europe (London, Xetra, etc.)
        // ZPRV, CNDX, VWRA, CSPX, IWVL often trade on LSE (LON) or Xetra (FRA/ETR)
        $etfTickers = ['ZPRV', 'CNDX', 'VWRA', 'CSPX', 'IWVL', 'EQQQ', 'EUNL', 'IS3N', 'SXR8'];
        if (in_array($ticker, $etfTickers)) {
            // Priority for these ETFs: Xetra (EUR) -> London (USD/GBP) -> Amsterdam
            array_unshift($exchanges, 'LON', 'AMS', 'SWX'); 
        }
        
        // Custom Manual Mapping for problematic tickers
        $customMap = [
            'CBK' => ['CBK:ETR', 'CBK.DE'],
            'LLOY' => ['LLOY:LON', 'LLOY.L'],
            'AVWS' => ['AVWS:NYSEARCA', 'AVWS'],
            'CYN' => ['CYN:NASDAQ', 'CYN'],
            'ZPRV' => ['ZPRV:ETR', 'ZPRV.DE'],
        ];

        if (array_key_exists($ticker, $customMap)) {
            foreach ($customMap[$ticker] as $m) $candidates[] = $m;
        }

        foreach ($exchanges as $ex) {
            $candidates[] = $ticker . ':' . $ex;
        }
        $candidates[] = $ticker;

        $data = [
            'ticker'         => $ticker,
            'current_price'  => null,
            'change_percent' => null,
            'company_name'   => null,
            'exchange'       => null,
            'currency'       => 'USD',
            'source'         => 'google_finance',
        ];

        foreach ($candidates as $code) {
            $url = 'https://www.google.com/finance/quote/' . urlencode($code) . '?hl=en';

            $html = $this->fetchUrl($url);
            if ($html === false || $html === '') {
                continue;
            }

            // Cena
            if (preg_match('~<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>(.*?)</div>~s', $html, $m)) {
                $text = strip_tags(htmlspecialchars_decode($m[1], ENT_QUOTES | ENT_HTML5));
                $text = str_replace(["\xc2\xa0", ' ', ','], '', $text);
                $text = preg_replace('/[^\d\.\-]/', '', $text);

                if ($text !== '' && is_numeric($text)) {
                    $price = (float)$text;
                    if ($price > 0) {
                        $data['current_price'] = $price;

                        // % změna – vezmeme číslo v závorkách "(...%)"
                        if (preg_match('/\(([+\-−]?[0-9,.]+)%\)/u', $html, $matches)) {
                            $data['change_percent'] = (float)str_replace(',', '', $matches[1]);
                        }

                        // Jméno firmy
                        if (preg_match('/<div[^>]*class="[^"]*zzDege[^"]*"[^>]*>([^<]+)<\/div>/i', $html, $matches)) {
                            $data['company_name'] = trim($matches[1]);
                        }

                        // Burza z kódu
                        if (preg_match('/([A-Z]+):/', $code, $matches)) {
                            $data['exchange'] = $matches[1];
                        }

                        // Currency detection from the page
                        // Look for currency code near the price or in specific elements
                        // Example: <div class="... ">USD</div> or similar
                        // Or infer from exchange
                        
                        if (preg_match('/<div[^>]*class="[^"]*C5N78d[^"]*"[^>]*>[^<]*?([A-Z]{3})[^<]*?<\/div>/', $html, $matches)) {
                             $data['currency'] = $matches[1];
                        } elseif (preg_match('/Currency in ([A-Z]{3})/', $html, $matches)) {
                             $data['currency'] = $matches[1];
                        } elseif ($data['exchange'] === 'FRA' || $data['exchange'] === 'ETR') {
                             $data['currency'] = 'EUR';
                        } elseif ($data['exchange'] === 'LON') {
                             $data['currency'] = 'GBP';
                        } elseif ($data['exchange'] === 'TSE') {
                             $data['currency'] = 'JPY';
                        } elseif (preg_match('/\.DE$/', $code)) {
                             // German stocks (.DE suffix) are in EUR
                             $data['currency'] = 'EUR';
                        } elseif (preg_match('/\.L$/', $code)) {
                             // London stocks are in GBP (or GBX)
                             $data['currency'] = 'GBP';
                        } elseif (preg_match('/\.PA$/', $code)) {
                             // Paris stocks are in EUR
                             $data['currency'] = 'EUR';
                        } elseif (preg_match('/\.AS$/', $code)) {
                             // Amsterdam stocks are in EUR
                             $data['currency'] = 'EUR';
                        }
                        
                        break; // máme data, konec
                    }
                }
            }
        }

        if ($data['current_price']) {
            return $data;
        }

        // Fallback: Yahoo Finance for Stocks
        return $this->fetchFromYahoo($ticker);
    }

    private function fetchFromYahoo(string $ticker): ?array
    {
        // Try raw ticker first (US stocks)
        $candidates = [$ticker];
        
        // Add suffixes for common European cases if ticker doesn't have one
        if (strpos($ticker, '.') === false) {
            $candidates[] = $ticker . '.DE'; // Germany
            $candidates[] = $ticker . '.L';  // London
            $candidates[] = $ticker . '.PA'; // Paris
            $candidates[] = $ticker . '.AS'; // Amsterdam
        }

        // Check Custom Map for Yahoo overrides
        $customMap = [
             'ZPRV' => ['ZPRV.DE'],
             'CBK' => ['CBK.DE'],
             'LLOY' => ['LLOY.L'],
             // AVWS on NYSE Arca usually works as AVWS on Yahoo? Let's check.
             // If AVWS fails, maybe it needs suffix? US stocks usually don't.
        ];
        if (isset($customMap[$ticker])) {
            $candidates = array_merge($candidates, $customMap[$ticker]);
        }

        foreach ($candidates as $yTicker) {
            try {
                $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($yTicker) . "?interval=1d&range=1d";
                $json = $this->fetchUrl($url);
                
                if ($json) {
                    $data = json_decode($json, true);
                    $result = $data['chart']['result'][0] ?? null;
                    
                    if ($result && isset($result['meta']['regularMarketPrice'])) {
                        $price = (float)$result['meta']['regularMarketPrice'];
                        $prev = (float)($result['meta']['chartPreviousClose'] ?? 0);
                        $changePct = 0;
                        if ($prev > 0) {
                            $changePct = (($price - $prev) / $prev) * 100;
                        }

                        if ($price > 0) {
                            // Extract company name from Yahoo response
                            $companyName = $result['meta']['shortName'] 
                                ?? $result['meta']['longName'] 
                                ?? $ticker;
                            
                            return [
                                'ticker'         => $ticker, // Keep original ID
                                'current_price'  => $price,
                                'change_percent' => $changePct,
                                'company_name'   => $companyName,
                                'exchange'       => $result['meta']['fullExchangeName'] ?? $result['meta']['exchangeName'] ?? 'Yahoo',
                                'currency'       => $result['meta']['currency'] ?? 'USD',
                                'source'         => 'yahoo',
                            ];
                        }
                    }
                }
            } catch (Exception $e) { /* ignore */ }
        }
        
        return null;
    }
}
