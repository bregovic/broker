<?php
namespace Broker\V3\Import\Csv;

use Broker\V3\Import\AbstractParser;

abstract class AbstractCsvParser extends AbstractParser {
    protected string $delimiter = ';';
    protected string $enclosure = '"';

    /**
     * Zpracuje CSV řetězec na pole polí
     */
    protected function getCsvRows(string $content): array {
        // Detekce oddělovače (pokud je tam čárka a ne středník)
        if (strpos($content, ';') === false && strpos($content, ',') !== false) {
            $this->delimiter = ',';
        }

        $lines = explode("\n", str_replace("\r", "", $content));
        $rows = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $rows[] = str_getcsv($line, $this->delimiter, $this->enclosure);
        }
        return $rows;
    }
}
