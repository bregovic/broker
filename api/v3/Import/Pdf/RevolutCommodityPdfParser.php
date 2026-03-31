<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

class RevolutCommodityPdfParser extends AbstractParser {
    
    public function getName(): string {
        return "Revolut Commodity PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Výpis v.*(XAU|XAG|XPT|XPD)|Smĕněno na.*(XAU|XAG|XPT|XPD)/ui', $content);
    }

    public function parse(string $content): array {
        $t = $content;
        $t = str_replace("\xc2\xa0", ' ', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);
        $t = trim($t);

        $out = [];
        $blockPattern = '/((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{2}\s[A-Za-z]{3}\s\d{4}))([\s\S]*?)(?=((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{2}\s[A-Za-z]{3}\s\d{4}))|$)/u';

        if (preg_match_all($blockPattern, $t, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawDate = $m[1];
                $dateIso = strpos($rawDate, '.') !== false ? $this->csDateToISO($rawDate) : $this->enDateToISO($rawDate);
                $block = trim($m[2]);
                if (!$dateIso || !$block) continue;

                // 1. BUY
                if (preg_match('/(?:Směněno na|Exchanged to)\s+(XAU|XAG|XPT|XPD)\s+([0-9][0-9.,\s]*)/ui', $block, $buyM)) {
                    $asset = strtoupper($buyM[1]);
                    $qtyNet = $this->parseNumber($buyM[2]);
                    
                    $feeAsset = 0;
                    if (preg_match('/(?:Poplatek|Fee):\s*([0-9][0-9.,\s]*)\s*(XAU|XAG|XPT|XPD)/ui', $block, $feeM)) {
                        $feeAsset = $this->parseNumber($feeM[1]);
                    }
                    
                    $qtyGross = $qtyNet + $feeAsset;
                    $amountCur = null; $currency = 'EUR';
                    if (preg_match('/(€|\$)\s*([0-9][0-9.,\s]*)\b(?!.*(€|\$)\s*[0-9])/', $block, $sym)) {
                        $amountCur = $this->parseNumber($sym[2]);
                        $currency = ($sym[1] === '$') ? 'USD' : 'EUR';
                    } else if (preg_match('/([0-9][0-9.,\s]*)\s*(EUR|USD|CZK|GBP)\b/ui', $block, $code)) {
                        $amountCur = $this->parseNumber($code[1]);
                        $currency = strtoupper($code[2]);
                    }

                    $out[] = $this->createTransaction($dateIso, $asset, 'BUY', $qtyNet, $amountCur, $currency);
                    continue;
                }

                // 2. SELL
                if (preg_match('/(?:Směněno na|Exchanged to)\s+(CZK|EUR|USD|GBP)\s+.*?([0-9][0-9.,\s]*)\s*(XAU|XAG|XPT|XPD)/ui', $block, $sellM)) {
                    $currency = strtoupper($sellM[1]);
                    $qty = $this->parseNumber($sellM[2]);
                    $asset = strtoupper($sellM[3]);
                    
                    $total = null;
                    if (preg_match('/([0-9][0-9.,\s]*)\s*' . $currency . '/ui', $block, $valM)) {
                        $total = $this->parseNumber($valM[1]);
                    }

                    $out[] = $this->createTransaction($dateIso, $asset, 'SELL', $qty, $total, $currency);
                    continue;
                }
            }
        }

        return $out;
    }

    private function createTransaction($date, $ticker, $type, $qty, $total, $currency): TransactionDTO {
        $dto = new TransactionDTO();
        $dto->date = $date;
        $dto->ticker = $ticker;
        $dto->type = $type;
        $dto->quantity = (float)$qty;
        $dto->pricePerUnit = (float)($qty ? abs($total / $qty) : 0);
        $dto->currency = $currency;
        $dto->totalAmount = (float)$total;
        $dto->source_broker = 'Revolut';
        $dto->metadata = ['source' => 'RevolutCommodityPdfParser'];
        $dto->brokerTradeId = "REV_COMM_" . md5($date . $ticker . $type . $qty . $total);
        return $dto;
    }
}
