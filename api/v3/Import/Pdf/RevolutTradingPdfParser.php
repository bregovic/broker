<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

class RevolutTradingPdfParser extends AbstractParser {
    
    private array $monthMap = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04', 'may' => '05', 'jun' => '06',
        'jul' => '07', 'aug' => '08', 'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
        'led' => '01', 'úno' => '02', 'bře' => '03', 'dub' => '04', 'kvě' => '05', 'čvn' => '06',
        'čvc' => '07', 'srp' => '08', 'zář' => '09', 'říj' => '10', 'lis' => '11', 'pro' => '12',
        'leden' => '01', 'únor' => '02', 'březen' => '03', 'duben' => '04', 'květen' => '05', 'červen' => '06',
        'červenec' => '07', 'srpen' => '08', 'září' => '09', 'říjen' => '10', 'listopad' => '11', 'prosinec' => '12'
    ];

    public function getName(): string {
        return "Revolut Trading PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Account Statement|USD Transactions|Výpis z účtu|Transakce v USD/ui', $content);
    }

    public function parse(string $content): array {
        $transactions = [];
        
        // Clean and Normalize like the JS version
        $cleanText = str_replace("\u{00A0}", ' ', $content);
        $cleanText = preg_replace('/\s{2,}/', ' ', $cleanText);
        
        // Split by Date (SUPER-ROBUST JS METHOD)
        // Matches: DD MMM YYYY | DD. MM. YYYY | DD MMM YYYY HH:MM:SS GMT
        $splitRegex = '/\s(?=(?:\d{1,2}\s[\w\x{00C0}-\x{024F}]{2,}\s\d{4}\s\d{2}:\d{2}:\d{2}\sGMT)|(?:\d{1,2}\s[\w\x{00C0}-\x{024F}]{2,}\s\d{4})|(?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4}))/u';
        $chunks = preg_split($splitRegex, $cleanText);

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if (empty($chunk)) continue;

            $date = $this->extractDate($chunk);
            if (!$date) continue;

            // --- TRADE LOGIC (Ported from JS Regex) ---
            // Pattern: Ticker Trade/Obchod - Market/Limit Qty Currency Price Side Currency Value ...
            $tradeRegex = '/\b([A-Z0-9.]{1,10})\s+(?:Trade|Obchod)\s+-\s+(?:Market|Limit|Tržní|Limitní)\s+([0-9.,\s]+)\s+([A-Z]{3})\s*([0-9.,\s]+)\s+(Buy|Sell|Nákup|Prodej)\s+([A-Z]{3})\s*([0-9.,\s\-]+)\s+([A-Z]{3})\s*([0-9.,\s\-]+)/iu';
            
            if (preg_match($tradeRegex, $chunk, $matches)) {
                $ticker = $matches[1];
                $qty = $this->parseNumber($matches[2]);
                $price = $this->parseNumber($matches[4]);
                $side = $matches[5];
                $total = $this->parseNumber($matches[7]);
                $currency = $matches[8]; // Resulting currency
                
                $transactions[] = $this->createTransaction($date, $ticker, $side, $qty, $total, $currency, $chunk);
                continue;
            }

            // --- DIVIDEND LOGIC ---
            $divRegex = '/\b([A-Z0-9.]{1,10})\s+(?:Dividend|Dividenda)\s+(USD|EUR|GBP|CZK)\s*([0-9.,\s]+)/iu';
            if (preg_match($divRegex, $chunk, $matches)) {
                $transactions[] = $this->createTransaction($date, $matches[1], 'DIVIDEND', 1, $this->parseNumber($matches[3]), $matches[2], $chunk);
            }
        }

        return $transactions;
    }

    private function extractDate(string $chunk): ?string {
        // 1. Timestamp GMT
        if (preg_match('/(\d{1,2})\s([\w\x{00C0}-\x{024F}]{2,})\s(\d{4})\s\d{2}:\d{2}:\d{2}\sGMT/u', $chunk, $m)) {
            return $this->formatDateStr($m[1], $m[2], $m[3]);
        }
        // 2. EN/CZ Simple (17 Feb 2021)
        if (preg_match('/(\d{1,2})\s([\w\x{00C0}-\x{024F}]{2,})\s(\d{4})/u', $chunk, $m)) {
            return $this->formatDateStr($m[1], $m[2], $m[3]);
        }
        // 3. Numeric (17. 2. 2021)
        if (preg_match('/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $chunk, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    private function formatDateStr($day, $monthStr, $year): string {
        $m = mb_strtolower(trim($monthStr, '.'));
        $m = mb_substr($m, 0, 3);
        $month = $this->monthMap[$m] ?? '01';
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseNumber(string $str): float {
        $clean = preg_replace('/\s+/', '', $str); // No spaces
        $clean = str_replace(',', '.', $clean);
        return (float)$clean;
    }

    private function createTransaction($date, $ticker, $type, $qty, $total, $currency, $chunk): TransactionDTO {
        $dto = new TransactionDTO();
        $dto->date = $date;
        $dto->ticker = $ticker;
        
        $t = mb_strtoupper($type);
        if (mb_stripos($t, 'NÁKUP') !== false || mb_stripos($t, 'BUY') !== false) $dto->type = 'BUY';
        elseif (mb_stripos($t, 'PRODEJ') !== false || mb_stripos($t, 'SELL') !== false) $dto->type = 'SELL';
        elseif (mb_stripos($t, 'DIVIDEND') !== false) $dto->type = 'DIVIDEND';
        else $dto->type = $t;

        $dto->quantity = abs($qty);
        $dto->totalAmount = abs($total);
        $dto->pricePerUnit = $qty ? abs($total / $qty) : 0;
        $dto->currency = strtoupper($currency);
        $dto->source_broker = 'Revolut';
        $dto->metadata = ['source' => 'RevolutTradingPdfParser', 'raw_chunk' => substr($chunk, 0, 200)];
        $dto->brokerTradeId = "REV_STOCK_" . md5($date . $ticker . $dto->type . $qty . $total);
        
        return $dto;
    }
}
