<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;

class RevolutTradingPdfParser extends AbstractParser {
    
    public function getName(): string {
        return "Revolut Trading PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Account Statement|USD Transactions/i', $content);
    }

    public function parse(string $content): array {
        $transactions = [];
        $lines = explode("\n", $content);

        // Pattern for trading transactions: 
        // 2023-01-01  AAPL  Buy - Market  1.00000000  150.00  USD  ...
        // Note: This is a simplified regex, real Revolut PDFs have split layouts
        foreach ($lines as $line) {
            // Regex attempt for Revolut Trading lines
            // Typical line: 2023-05-15  AAPL  Buy - Market  2.5  172.50  USD
            if (preg_match('/(\d{4}-\d{2}-\d{2})\s+([A-Z0-9.]+)\s+(Buy|Sell).*?\s+([\d.]+)\s+([\d.]+)\s+([A-Z]{3})/', $line, $matches)) {
                $transactions[] = [
                    'date' => $matches[1],
                    'ticker' => $matches[2],
                    'trans_type' => strtolower($matches[3]),
                    'amount' => (float)$matches[4],
                    'price' => (float)$matches[5],
                    'currency' => $matches[6],
                    'platform' => 'Revolut',
                    'product_type' => 'Akcie'
                ];
            }
            
            // Regex for Dividends
            if (preg_match('/(\d{4}-\d{2}-\d{2})\s+([A-Z0-9.]+)\s+Dividend.*?\s+([\d.]+)\s+([A-Z]{3})/', $line, $matches)) {
                $transactions[] = [
                    'date' => $matches[1],
                    'ticker' => $matches[2],
                    'trans_type' => 'dividend',
                    'amount' => (float)$matches[3],
                    'price' => 1,
                    'currency' => $matches[4],
                    'platform' => 'Revolut',
                    'product_type' => 'Akcie'
                ];
            }
        }

        return $transactions;
    }
}
