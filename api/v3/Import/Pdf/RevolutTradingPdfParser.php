<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

class RevolutTradingPdfParser extends AbstractParser {
    
    public function getName(): string {
        return "Revolut Trading PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Account Statement|USD Transactions|Výpis z účtu|Transakce v USD/ui', $content);
    }

    public function parse(string $content): array {
        $transactions = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            // Regex for Trades
            if (preg_match('/(\d{4}-\d{2}-\d{2})\s+([A-Z0-9.]+)\s+(Buy|Sell|Nákup|Prodej).*?\s+([\d.]+)\s+([\d.]+)\s+([A-Z]{3})/ui', $line, $matches)) {
                $transactions[] = $this->createTransaction($matches[1], $matches[2], $matches[3], $matches[4], $matches[5], $matches[6]);
            }
            
            // Regex for Dividends
            if (preg_match('/(\d{4}-\d{2}-\d{2})\s+([A-Z0-9.]+)\s+(Dividend|Dividenda).*?([\d.]+)\s+([A-Z]{3})/ui', $line, $matches)) {
                $transactions[] = $this->createTransaction($matches[1], $matches[2], 'DIVIDEND', 1, $matches[4], $matches[5]);
            }
        }

        return $transactions;
    }

    private function createTransaction($date, $ticker, $type, $qty, $total, $currency, $notes = '', $price = null): TransactionDTO {
        $dto = new TransactionDTO();
        $dto->date = $date;
        $dto->ticker = $ticker;
        
        // Normalize type
        $t = strtoupper($type);
        if (strpos($t, 'NÁKUP') !== false || strpos($t, 'BUY') !== false) $dto->type = 'BUY';
        elseif (strpos($t, 'PRODEJ') !== false || strpos($t, 'SELL') !== false) $dto->type = 'SELL';
        elseif (strpos($t, 'DIVIDEND') !== false) $dto->type = 'DIVIDEND';
        else $dto->type = $t;

        $dto->quantity = (float)$qty;
        $dto->pricePerUnit = (float)($price ?: ($qty ? abs($total / $qty) : 0));
        $dto->currency = $currency;
        $dto->totalAmount = (float)$total;
        $dto->source_broker = 'Revolut';
        $dto->metadata = ['notes' => $notes, 'source' => 'RevolutTradingPdfParser'];
        $dto->brokerTradeId = "REV_STOCK_" . md5($date . $ticker . $dto->type . $qty . $total);
        
        return $dto;
    }
}
