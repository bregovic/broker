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
     * Identifies the broker and config based on rules and processes the file.
     */
    public function processFile(string $filePath, string $originalName = ''): array {
        if (!file_exists($filePath)) {
            throw new \Exception("Soubor nebyl nalezen.");
        }

        $filename = $originalName ?: basename($filePath);
        $content = $this->extractContent($filePath, $filename);
        
        // 1. DISCOVERY - Find rule using DB
        $rule = $this->discoverRule($filename, $content);
        if (!$rule) {
            throw new \Exception("Chyba: Žádný z poskytovatelů nebyl rozpoznán pro soubor '$filename'.");
        }

        // 2. PARSING
        $parserClass = $rule['parser_class'];
        if (!class_exists($parserClass)) {
            // Lazy load or manual requirement might be needed depending on autoloader
            $this->loadParserFile($parserClass);
        }

        if (!class_exists($parserClass)) {
            throw new \Exception("Parser class '$parserClass' nebyla nalezena.");
        }

        /** @var AbstractParser $parser */
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
                if (preg_match('/' . $rule['content_regex'] . '/i', $content)) {
                    $isMatch = true;
                }
            }

            // 2. Check filename pattern if content didn't match or was empty
            if (!$isMatch && $rule['file_pattern']) {
                if (preg_match('/' . $rule['file_pattern'] . '/i', $filename)) {
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
        if (file_exists($fullPath)) {
            require_once $fullPath;
        }
    }
}
