<?php
namespace Broker\V3\Import\Csv;

use Broker\V3\Import\TransactionDTO;

/**
 * Konkrétní implementace pro Fio banku (CSV export)
 */
class FioCsvParser extends AbstractCsvParser {

    public function getName(): string {
        return "Fio Bank (CSV)";
    }

    public function canParse(string $content, string $filename): bool {
        // Jednoduchá detekce na základě hlavičky nebo názvu souboru
        return (str_contains($content, 'fio') || str_contains(strtolower($filename), 'fio')) 
               && str_contains($content, ';');
    }

    public function parse(string $content): array {
        $rows = $this->getCsvRows($content);
        $transactions = [];

        // Přeskočíme hlavičku (pokud tam je)
        foreach ($rows as $index => $row) {
            if ($index === 0 || count($row) < 5) continue;

            $dto = new TransactionDTO();
            // Příklad mapování (přizpůsob si podle tvého reálného exportu)
            $dto->date = $row[1]; // Předpokládáme Datum
            $dto->ticker = $row[3]; // Předpokládáme Symbol/ISIN
            $dto->type = $this->detectType($row[2]); // Rozdíl mezi nákupem/prodejem
            $dto->quantity = $this->cleanNumber($row[5]);
            $dto->pricePerUnit = $this->cleanNumber($row[6]);
            $dto->currency = $row[7] ?? 'CZK';
            $dto->totalAmount = $this->cleanNumber($row[8] ?? 0);
            $dto->brokerTradeId = $row[0]; //ID pokynu
            $dto->source_broker = "Fio";

            $transactions[] = $dto;
        }

        return $transactions;
    }

    private function detectType($val): string {
        $val = mb_strtolower($val);
        if (str_contains($val, 'nákup')) return 'BUY';
        if (str_contains($val, 'prodej')) return 'SELL';
        if (str_contains($val, 'dividenda')) return 'DIVIDEND';
        return 'UNKNOWN';
    }
}
