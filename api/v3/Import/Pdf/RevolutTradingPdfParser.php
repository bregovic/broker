<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Revolut Trading PDF Parser
 */
class RevolutTradingPdfParser extends AbstractParser {
    public function getName(): string {
        return "Revolut Trading (PDF)";
    }

    public function canParse(string $content, string $filename): bool {
        // Discovery is handled by database rules, but we can verify here if needed
        return str_contains($content, 'Account Statement') && str_contains($content, 'Revolut');
    }

    public function parse(string $content): array {
        $transactions = [];
        // Tady bude tvá budoucí logika parsování textu z PDF...
        // Zatím vracíme jako příklad jeden DTO, aby bylo vidět, že to protéká.
        
        // REVOLUT TRADING PDF PARSING LOGIC (Regex based)
        // Příklad detekce obchodu (Buy/Sell) v textu PDF:
        // Market Buy | AAPL | 12.03.2024 | 1.5 | 150.20 | USD
        
        // PRO TESTOVÁNÍ: Pokud v PDF najdeme "Market Buy", zkusíme simulovat záchyt
        // (V reálu tu bude pořádný match_all regex)
        if (preg_match('/Price per share\s+([\d,.]+)\s+([A-Z]{3})/i', $content, $m)) {
            // Jen ukázka, že to něco našlo
        }

        return $transactions;
    }
}
