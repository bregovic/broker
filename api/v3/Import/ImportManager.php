<?php
namespace Broker\V3\Import;

/**
 * ImportManager (Modernized Object-Oriented Version)
 * Handles file discovery using database rules and coordinates parsing.
 */
class ImportManager {
    private \PDO $pdo;
    private array $parsers = [];

    public function __construct(\PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Analyzes a file and returns metadata + potential transactions without saving.
     */
    public function analyzeFile(string $filePath, string $originalName = '', ?int $ruleId = null): array {
        if (!file_exists($filePath)) {
            throw new \Exception("Soubor nebyl nalezen.");
        }

        $filename = $originalName ?: basename($filePath);
        $content = $this->extractContent($filePath, $filename);
        
        // 1. DISCOVERY / MANUAL RULE
        $rule = null;
        if ($ruleId) {
            $stmt = $this->pdo->prepare("SELECT * FROM broker_import_rules WHERE id = ?");
            $stmt->execute([$ruleId]);
            $rule = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$rule) {
            $rule = $this->discoverRule($filename, $content);
        }
        
        // 2. PARSING (Dry Run)
        $transactions = [];
        $parserName = 'Neznámý';
        $parserClass = $rule ? $rule['parser_class'] : null;

        if ($parserClass) {
            if (!class_exists($parserClass)) {
                $this->loadParserFile($parserClass);
            }
            if (class_exists($parserClass)) {
                $parser = new $parserClass();
                $parserName = $parser->getName();
                $transactions = $parser->parse($content);
            }
        }

        return [
            'filename' => $filename,
            'extension' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            'broker' => $rule ? $rule['broker_name'] : 'Neznámý',
            'parser' => $parserName,
            'parser_class' => $parserClass,
            'tx_count' => count($transactions),
            'rule_id' => $rule ? $rule['id'] : null,
            'asset_type' => $this->guessAssetTypeFromRule($rule),
            'content_preview' => mb_substr($content, 0, 500)
        ];
    }

    /**
     * Processes the file using a specific rule ID or auto-discovery.
     */
    public function processFile(string $filePath, string $originalName = '', ?int $ruleId = null): array {
        if (!file_exists($filePath)) {
            throw new \Exception("Soubor nebyl nalezen.");
        }

        $filename = $originalName ?: basename($filePath);
        $content = $this->extractContent($filePath, $filename);
        
        $rule = null;
        if ($ruleId) {
            $stmt = $this->pdo->prepare("SELECT * FROM broker_import_rules WHERE id = ?");
            $stmt->execute([$ruleId]);
            $rule = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$rule) {
            $rule = $this->discoverRule($filename, $content);
        }

        if (!$rule) {
            throw new \Exception("Chyba: Žádný z poskytovatelů nebyl rozpoznán pro soubor '$filename'.");
        }

        $parserClass = $rule['parser_class'];
        if (!class_exists($parserClass)) {
            $this->loadParserFile($parserClass);
        }

        $parser = new $parserClass();
        $transactions = $parser->parse($content);

        return [
            'broker' => $rule['broker_name'],
            'config' => $rule['config_name'],
            'parser' => $parser->getName(),
            'transactions' => $transactions
        ];
    }

    /**
     * Returns all configured import rules for manual selection.
     */
    public function getAvailableRules(): array {
        $stmt = $this->pdo->query("SELECT id, config_name AS rule_name, broker_name FROM broker_import_rules ORDER BY broker_name");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function guessAssetTypeFromRule(?array $rule): string {
        if (!$rule) return 'Neznámý';
        $name = strtolower($rule['broker_name'] . ' ' . $rule['config_name']);
        if (strpos($name, 'crypto') !== false) return 'Crypto';
        if (strpos($name, 'commodity') !== false) return 'Komodita';
        return 'Akcie/ETF';
    }

    /**
     * Extracts text content from file (supports PDF via pdftotext)
     */
    private function extractContent(string $path, string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if ($ext === 'pdf') {
            // Use pdftotext (provided by poppler-utils in Docker)
            $escapedPath = escapeshellarg($path);
            $content = shell_exec("pdftotext -layout $escapedPath -");
            if ($content === null) {
                throw new \Exception("Chyba při extrakci textu z PDF. Je nainstalován poppler-utils?");
            }
            return $content;
        }

        return file_get_contents($path);
    }

    /**
     * Looks up matching rule in the database
     */
    private function discoverRule(string $filename, string $content): ?array {
        $stmt = $this->pdo->query("SELECT * FROM broker_import_rules");
        $allRules = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($allRules as $rule) {
            $isMatch = false;

            // 1. Check content regex (high priority)
            if ($rule['content_regex']) {
                if (preg_match('/' . $rule['content_regex'] . '/ui', $content)) {
                    $isMatch = true;
                }
            }

            // 2. Check filename pattern if content didn't match or was empty
            if (!$isMatch && $rule['file_pattern']) {
                if (preg_match('/' . $rule['file_pattern'] . '/ui', $filename)) {
                    $isMatch = true;
                }
            }

            if ($isMatch) {
                return $rule;
            }
        }

        return null;
    }

    private function loadParserFile(string $className): void {
        // Simple mapping Broker\V3\Import\Pdf\RevolutTradingPdfParser -> Import/Pdf/RevolutTradingPdfParser.php
        $relPath = str_replace(['Broker\\V3\\Import\\', '\\'], ['', '/'], $className) . '.php';
        $fullPath = __DIR__ . '/' . $relPath;
        
        // Handle namespaced cases or direct files
        if (!file_exists($fullPath)) {
             // Fallback to basename just in case
             $fullPath = __DIR__ . '/' . basename($relPath);
        }

        if (file_exists($fullPath)) {
            require_once $fullPath;
        }
    }
}
