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

        // Debug: Log texture overview for refinement (tmp for dev)
        // file_put_contents(__DIR__ . '/debug_last_pdf.txt', substr($content, 0, 5000));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // 1. REFRESHED TRADING REGEX (More flexible for Czech dates and commas)
            // Pattern: [Date] [Ticker] [Type] [Quantity] [Price] [Currency]
            // Example CS: 2021-02-17 AAPL Nákup 1,50 150,00 USD
            // Example CS: 17. úno. 2021 AAPL Nákup 1,50 150,00 USD
            $datePattern = '((\d{4}-\d{2}-\d{2})|(\d{1,2}\.\s+\w+\.?\s+\d{4}))';
            $numPattern = '([\d\s]+[,.]\d+)'; // Handles "1 234,56" or "123.45"
            $tickerPattern = '([A-Z0-9^.]+)';
            $typePattern = '(Buy|Sell|Nákup|Prodej|Market Buy|Limit Buy|Market Sell|Limit Sell)';
            
            $masterPattern = '/' . $datePattern . '\s+' . $tickerPattern . '\s+' . $typePattern . '.*?\s+' . $numPattern . '\s+' . $numPattern . '\s+([A-Z]{3})/ui';

            if (preg_match($masterPattern, $line, $matches)) {
                // Matches: 1:full_date, 4:ticker, 5:type, 6:qty, 7:total, 8:curr
                $transactions[] = $this->createTransaction(
                    $matches[1], 
                    $matches[4], 
                    $matches[5], 
                    str_replace(',', '.', $matches[6]), 
                    str_replace(',', '.', $matches[7]), 
                    $matches[8]
                );
                continue;
            }
            
            // 2. DIVIDEND REGEX
            if (preg_match('/' . $datePattern . '\s+' . $tickerPattern . '\s+(Dividend|Dividenda).*?\s+' . $numPattern . '\s+([A-Z]{3})/ui', $line, $matches)) {
                 $transactions[] = $this->createTransaction(
                    $matches[1], 
                    $matches[4], 
                    'DIVIDEND', 
                    1, 
                    str_replace(',', '.', $matches[5]), 
                    $matches[6]
                );
            }
        }

        return $transactions;
    }

    private function createTransaction($date, $ticker, $type, $qty, $total, $currency, $notes = '', $price = null): TransactionDTO {
        $dto = new TransactionDTO();
        
        // Basic date clean
        $dto->date = $this->normalizeDate($date);
        $dto->ticker = $ticker;
        
        // Normalize type
        $t = strtoupper($type);
        if (mb_stripos($t, 'Nákup') !== false || mb_stripos($t, 'Buy') !== false) $dto->type = 'BUY';
        elseif (mb_stripos($t, 'Prodej') !== false || mb_stripos($t, 'Sell') !== false) $dto->type = 'SELL';
        elseif (mb_stripos($t, 'Dividend') !== false) $dto->type = 'DIVIDEND';
        else $dto->type = $t;

        $dto->quantity = (float)preg_replace('/[^\d.]/', '', $qty);
        $totalVal = (float)preg_replace('/[^\d.]/', '', $total);
        $dto->pricePerUnit = (float)($price ?: ($dto->quantity ? abs($totalVal / $dto->quantity) : 0));
        $dto->currency = $currency;
        $dto->totalAmount = $totalVal;
        $dto->source_broker = 'Revolut';
        $dto->metadata = ['notes' => $notes, 'source' => 'RevolutTradingPdfParser', 'raw_type' => $type];
        $dto->brokerTradeId = "REV_STOCK_" . md5($dto->date . $ticker . $dto->type . $dto->quantity . $dto->totalAmount);
        
        return $dto;
    }

    private function normalizeDate(string $rawDate): string {
        // Try YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) return $rawDate;
        
        // Handle Czech stuff like "17. úno. 2021" -> convert to sth strtotime likes maybe?
        // For now, if it fails, return raw or today to avoid crash, but better to keep raw
        return $rawDate; 
    }
}
