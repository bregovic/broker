<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;

class RevolutCommodityPdfParser extends AbstractParser {
    
    public function getName(): string {
        return "Revolut Commodity PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return preg_match('/Výpis v.*(XAU|XAG|XPT|XPD)|Smĕněno na.*(XAU|XAG|XPT|XPD)/i', $content);
    }

    public function parse(string $content): array {
        $t = $content;
        $t = str_replace("\xc2\xa0", ' ', $t);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);
        $t = trim($t);

        $out = [];
        $blockPattern = '/((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{2}\s[A-Za-z]{3}\s\d{4}))([\s\S]*?)(?=((?:\d{1,2}\.\s*\d{1,2}\.\s*\d{4})|(?:\d{2}\s[A-Za-z]{3}\s\d{4}))|$)/';

        if (preg_match_all($blockPattern, $t, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $rawDate = $m[1];
                $dateIso = strpos($rawDate, '.') !== false ? $this->csDateToISO($rawDate) : $this->enDateToISO($rawDate);
                $block = trim($m[2]);
                if (!$dateIso || !$block) continue;

                // 1. BUY: "Směněno na XAU ..."
                if (preg_match('/(?:Směněno na|Exchanged to)\s+(XAU|XAG|XPT|XPD)\s+([0-9][0-9.,\s]*)/i', $block, $buyM)) {
                    $assetNum = $buyM[1];
                    $qtyNet = $this->parseNumber($buyM[2]);
                    
                    // Fee in asset
                    $feeAsset = 0;
                    if (preg_match('/(?:Poplatek|Fee):\s*([0-9][0-9.,\s]*)\s*(XAU|XAG|XPT|XPD)/i', $block, $feeM)) {
                        $feeAsset = $this->parseNumber($feeM[1]);
                    }
                    
                    $qtyGross = $qtyNet + $feeAsset;

                    // Find fiat amount
                    $amountCur = null; $currency = 'EUR';
                    if (preg_match('/(€|\$)\s*([0-9][0-9.,\s]*)\b(?!.*(€|\$)\s*[0-9])/', $block, $sym)) {
                        $amountCur = $this->parseNumber($sym[2]);
                        $currency = ($sym[1] === '$') ? 'USD' : 'EUR';
                    } else if (preg_match('/([0-9][0-9.,\s]*)\s*(EUR|USD|CZK|GBP)\b/i', $block, $code)) {
                        $amountCur = $this->parseNumber($code[1]);
                        $currency = strtoupper($code[2]);
                    }

                    $unitPrice = ($qtyGross && $amountCur !== null) ? ($amountCur / $qtyGross) : null;

                    $out[] = [
                        'date' => $dateIso,
                        'ticker' => strtoupper($assetNum),
                        'trans_type' => 'buy',
                        'amount' => $qtyNet,
                        'price' => $unitPrice,
                        'currency' => $currency,
                        'platform' => 'Revolut',
                        'product_type' => 'Komodity',
                        'notes' => 'Commodity exchange (buy)'
                    ];
                    continue;
                }

                // 2. SELL: "Směněno na CZK ..."
                if (preg_match('/(?:Směněno na|Exchanged to)\s+(CZK|EUR|USD|GBP)\s+.*?([0-9][0-9.,\s]*)\s*(XAU|XAG|XPT|XPD)/i', $block, $sellM)) {
                    $currency = strtoupper($sellM[1]);
                    $qty = $this->parseNumber($sellM[2]);
                    $asset = strtoupper($sellM[3]);
                    
                    $total = null;
                    if (preg_match('/([0-9][0-9.,\s]*)\s*' . $currency . '/i', $block, $valM)) {
                        $total = $this->parseNumber($valM[1]);
                    }

                    $out[] = [
                        'date' => $dateIso,
                        'ticker' => $asset,
                        'trans_type' => 'sell',
                        'amount' => $qty,
                        'price' => ($qty ? $total / $qty : null),
                        'currency' => $currency,
                        'platform' => 'Revolut',
                        'product_type' => 'Komodity',
                        'notes' => 'Commodity exchange (sell)'
                    ];
                    continue;
                }
            }
        }

        return $out;
    }
}
