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
        // Keep only digits, separators and sign (drops spaces, NBSP, currency symbols).
        $val = preg_replace('/[^0-9.,\-]/', '', (string)$val);
        if ($val === '' || $val === '-') return null;

        $lastComma = strrpos($val, ',');
        $lastDot   = strrpos($val, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Both present: the RIGHTMOST separator is the decimal point.
            if ($lastComma > $lastDot) {
                // European "1.267,50": dots = thousands, comma = decimal
                $val = str_replace('.', '', $val);
                $val = str_replace(',', '.', $val);
            } else {
                // US "1,267.50": commas = thousands
                $val = str_replace(',', '', $val);
            }
        } elseif ($lastComma !== false) {
            // Only comma: exactly 3 trailing digits => thousands ("1,267"),
            // otherwise treat as a decimal separator ("36,2" / "0,0362").
            $decimals = strlen($val) - $lastComma - 1;
            $val = ($decimals === 3) ? str_replace(',', '', $val) : str_replace(',', '.', $val);
        }
        // else: only a dot, or a plain integer -> already parseable

        return (float) $val;
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
