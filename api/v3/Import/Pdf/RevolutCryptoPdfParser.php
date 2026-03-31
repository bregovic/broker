<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

class RevolutCryptoPdfParser extends AbstractParser {
    
    public function getName(): string {
        return "Revolut Crypto PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Výpis z účtu s kryptomĕnami|Crypto.*Statement|Odměna za staking/ui', $content);
    }

    public function parse(string $content): array {
        $t = $content;
        $t = str_replace("\xc2\xa0", ' ', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);
        $t = trim($t);

        $out = [];
        $blockPattern = '/((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{1,2}\s[A-Za-z]{3}\s\d{4}))([\s\S]*?)(?=((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{1,2}\s[A-Za-z]{3}\s\d{4}))|$)/u';
        
        if (preg_match_all($blockPattern, $t, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawDate = $m[1];
                $dateIso = strpos($rawDate, '.') !== false ? $this->csDateToISO($rawDate) : $this->enDateToISO($rawDate);
                $block = trim($m[2]);
                if (!$dateIso || !$block) continue;

                // 1. Staking / Rewards
                if (preg_match('/([A-Z]{2,10})\s+(?:Odměna|Staking|Reward|Interest)\b[^\d]*([0-9][0-9.,]*)/ui', $block, $rw)) {
                    $symbol = strtoupper($rw[1]);
                    $qty = $this->parseNumber($rw[2]);
                    if ($symbol && $qty) {
                        $out[] = $this->createTransaction($dateIso, $symbol, 'REVENUE', $qty, 0, 'CZK', 'Staking/Reward');
                        continue;
                    }
                }

                // 2. Trades (Buy/Sell)
                if (preg_match('/(Buy|Sell|Nákup|Prodej)\s+([A-Z0-9]{2,10}).*?([0-9][0-9.,]*)\s*[A-Z0-9]{2,10}.*?(€|\$|CZK|USD|EUR)\s*([0-9][0-9.,]*)/ui', $block, $trade)) {
                    $side = strtoupper($trade[1]);
                    $symbol = strtoupper($trade[2]);
                    $qty = $this->parseNumber($trade[3]);
                    $curTok = $trade[4];
                    $total = $this->parseNumber($trade[5]);
                    
                    $currency = $this->symToFiat($curTok);
                    $type = (strpos($side, 'SELL') !== false || strpos($side, 'PRODEJ') !== false) ? 'SELL' : 'BUY';
                    
                    $out[] = $this->createTransaction($dateIso, $symbol, $type, abs($qty), $total, $currency, "Trade $side");
                    continue;
                }
            }
        }

        return $out;
    }

    private function symToFiat($s): string {
        if ($s === '$') return 'USD';
        if ($s === '€') return 'EUR';
        return strtoupper($s) ?: 'CZK';
    }

    private function createTransaction($date, $ticker, $type, $qty, $total, $currency, $notes = ''): TransactionDTO {
        $dto = new TransactionDTO();
        $dto->date = $date;
        $dto->ticker = $ticker;
        $dto->type = $type;
        $dto->quantity = (float)$qty;
        $dto->pricePerUnit = (float)($qty ? abs($total / $qty) : 0);
        $dto->currency = $currency;
        $dto->totalAmount = (float)$total;
        $dto->source_broker = 'Revolut';
        $dto->metadata = ['notes' => $notes, 'source' => 'RevolutCryptoPdfParser'];
        $dto->brokerTradeId = "REV_CRYPTO_" . md5($date . $ticker . $type . $qty . $total);
        return $dto;
    }
}
