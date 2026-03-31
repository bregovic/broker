<?php
namespace Broker\V3\Import;

class ImportManager {
    /** @var AbstractParser[] */
    private array $parsers = [];

    public function registerParser(AbstractParser $parser): void {
        $this->parsers[] = $parser;
    }

    /**
     * Zkusí najít vhodný parser a zpracovat soubor
     * @return array ['parser' => string, 'transactions' => TransactionDTO[]]
     */
    public function processFile(string $filePath): array {
        if (!file_exists($filePath)) {
            throw new \Exception("Soubor nebyl nalezen: $filePath");
        }

        $content = file_get_contents($filePath);
        $filename = basename($filePath);

        foreach ($this->parsers as $parser) {
            if ($parser->canParse($content, $filename)) {
                return [
                    'parser' => $parser->getName(),
                    'transactions' => $parser->parse($content)
                ];
            }
        }

        throw new \Exception("Chyba: Žádný z registrovaných parserů nerozpoznal formát souboru '$filename'.");
    }
}
