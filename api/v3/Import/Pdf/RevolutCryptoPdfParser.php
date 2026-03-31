<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;

class RevolutCryptoPdfParser extends AbstractParser {
    
    public function getName(): string {
        return "Revolut Crypto PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Výpis z účtu s kryptomĕnami|Crypto.*Statement/i', $content);
    }

    public function parse(string $content): array {
        // Normalize text similarly to JS version
        $t = $content;
        $t = str_replace("\xc2\xa0", ' ', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);
        $t = trim($t);

        $out = [];
        
        // Block splitting regex (CZ and EN dates)
        $blockPattern = '/((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{1,2}\s[A-Za-z]{3}\s\d{4}))([\s\S]*?)(?=((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{1,2}\s[A-Za-z]{3}\s\d{4}))|$)/';
        
        if (preg_match_all($blockPattern, $t, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawDate = $m[1];
                $dateIso = strpos($rawDate, '.') !== false ? $this->csDateToISO($rawDate) : $this->enDateToISO($rawDate);
                $block = trim($m[2]);
                if (!$dateIso || !$block) continue;

                // 1. Staking / Rewards
                // Pattern from JS: /([A-Z]{2,10})\s+(?:Odměna(?:\s+za\s+staking)?|Staking(?:\s+reward)?|Reward|Interest)\b[^\d]*([0-9][0-9.,]*)/i
                if (preg_match('/([A-Z]{2,10})\s+(?:Odměna|Staking|Reward|Interest)\b[^\d]*([0-9][0-9.,]*)/i', $block, $rw)) {
                    $symbol = strtoupper($rw[1]);
                    $qty = $this->parseNumber($rw[2]);
                    if ($symbol && $qty) {
                        $out[] = $this->createTransaction($dateIso, $symbol, 'revenue', $qty, 0, 'CZK', 'Staking/Reward');
                        continue;
                    }
                }

                // 2. Trades (Buy/Sell)
                // Pattern from JS: /(Buy|Sell|Nákup|Prodej)\s+([A-Z0-9]{2,10}).*?([0-9][0-9.,]*)\s*[A-Z0-9]{2,10}.*?(€|\$|CZK|USD|EUR)\s*([0-9][0-9.,]*)/i
                if (preg_match('/(Buy|Sell|Nákup|Prodej)\s+([A-Z0-9]{2,10}).*?([0-9][0-9.,]*)\s*[A-Z0-9]{2,10}.*?(€|\$|CZK|USD|EUR)\s*([0-9][0-9.,]*)/i', $block, $trade)) {
                    $side = $trade[1];
                    $symbol = strtoupper($trade[2]);
                    $qty = $this->parseNumber($trade[3]);
                    $curTok = $trade[4];
                    $total = $this->parseNumber($trade[5]);
                    
                    $currency = $this->symToFiat($curTok);
                    $transType = preg_match('/Sell|Prodej/i', $side) ? 'sell' : 'buy';
                    
                    $price = ($qty && $total !== null) ? ($total / abs($qty)) : null;

                    $out[] = $this->createTransaction($dateIso, $symbol, $transType, abs($qty), $total, $currency, "Trade $side", $price);
                    continue;
                }
            }
        }

        // Also check for tabular section if needed, but the block logic usually covers Revolut PDF well
        return $out;
    }

    private function symToFiat($s): string {
        if ($s === '$') return 'USD';
        if ($s === '€') return 'EUR';
        return strtoupper($s) ?: 'CZK';
    }

    private function createTransaction($date, $ticker, $type, $amount, $total, $currency, $notes = '', $price = null): array {
        return [
            'date' => $date,
            'ticker' => $ticker,
            'trans_type' => $type,
            'amount' => $amount,
            'price' => $price ?: ($amount ? $total / $amount : 0),
            'currency' => $currency,
            'platform' => 'Revolut',
            'product_type' => 'Kryptoměny',
            'notes' => $notes
        ];
    }
}
