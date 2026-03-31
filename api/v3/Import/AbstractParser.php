<?php
namespace Broker\V3\Import;

abstract class AbstractParser {
    
    /**
     * Identifikátor parseru (např. 'FioCsv', 'RevolutPdf')
     */
    abstract public function getName(): string;

    /**
     * Vrátí true, pokud tento parser dokáže zpracovat daný soubor
     */
    abstract public function canParse(string $content, string $filename): bool;

    /**
     * Hlavní metoda pro zpracování souboru
     * @return TransactionDTO[]
     */
    abstract public function parse(string $content): array;

    /**
     * Helper pro čištění čísel (zrušení tisícových oddělovačů, čárek atd.)
     */
    protected function cleanNumber($val): float {
        $val = str_replace([' ', "\xc2\xa0"], '', $val); // Smazat mezery
        $val = str_replace(',', '.', $val); // Čárka -> Tečka
        return (float) $val;
    }
}
