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
    protected function parseNumber($val): ?float {
        if ($val === null || $val === '') return null;
        $val = str_replace(["\xc2\xa0", ' '], '', $val); // Smazat mezery
        $val = str_replace(',', '.', $val); // Čárka -> Tečka
        return (float) preg_replace('/[^0-9.-]/', '', $val);
    }

    protected function csDateToISO(string $date): string {
        // "17. 2. 2021" -> "2021-02-17"
        if (preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return '';
    }

    protected function enDateToISO(string $date): string {
        // "17 Feb 2021" -> "2021-02-17"
        $months = [
            'Jan'=>'01','Feb'=>'02','Mar'=>'03','Apr'=>'04','May'=>'05','Jun'=>'06',
            'Jul'=>'07','Aug'=>'08','Sep'=>'09','Oct'=>'10','Nov'=>'11','Dec'=>'12'
        ];
        if (preg_match('/(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})/', $date, $m)) {
            $month = $months[ucfirst(strtolower($m[2]))] ?? '01';
            return sprintf('%04d-%s-%02d', $m[3], $month, $m[1]);
        }
        return '';
    }
}
