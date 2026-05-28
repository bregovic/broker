<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

class IbkrPdfParser extends AbstractParser {

    public function getName(): string {
        return "Interactive Brokers PDF";
    }

    public function canParse(string $content, string $filename): bool {
        $isIbkr = preg_match('/TransactionsCZK|Transactions|Interactive Brokers|Time Period:|Transaction History/ui', $content);
        $isFilenameMatch = preg_match('/ibkr/ui', $filename);
        return $isIbkr || $isFilenameMatch;
    }

    public function parse(string $content): array {
        if (empty($content)) {
            return [];
        }

        // 1) Normalizace textu
        $cleanText = str_replace("\u{00A0}", ' ', $content);
        $cleanText = str_replace("\r", "\n", $cleanText);
        $cleanText = trim($cleanText);

        $lines = explode("\n", $cleanText);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines);

        // 2) Slepíme rozbitá data
        $lines = $this->fixBrokenDates($lines);

        // 3) Slepíme řádky podle data (jeden řádek = jedna transakce)
        $lines = $this->mergeLinesByDate($lines);

        $transactions = [];
        $processedKeys = [];

        foreach ($lines as $line) {
            // Musí začínat datem ve formátu YYYY-MM-DD
            if (!preg_match('/^(20\d{2}-\d{2}-\d{2})/', $line, $dateMatch)) {
                continue;
            }
            $date = $dateMatch[1];

            // 1) BUY
            if (strpos($line, ' Buy ') !== false) {
                $parts = preg_split('/\s+/', $line);
                $buyIndex = array_search('Buy', $parts);

                if ($buyIndex !== false && $buyIndex > 0 && $buyIndex < count($parts) - 4) {
                    $symbol = $parts[$buyIndex + 1];
                    $quantity = (float)($parts[$buyIndex + 2] ?? 0);
                    $price = (float)($parts[$buyIndex + 3] ?? 0);
                    $origCurrency = $parts[$buyIndex + 4] ?? 'USD';

                    $czkAmount = $this->extractLastAmountFromParts($parts);
                    if ($symbol && $czkAmount !== null) {
                        $txKey = "{$date}|{$symbol}|Buy|" . number_format(abs($czkAmount), 2, '.', '') . "|" . number_format(abs($quantity), 4, '.', '');
                        if (!in_array($txKey, $processedKeys)) {
                            $processedKeys[] = $txKey;

                            $dto = new TransactionDTO();
                            $dto->date = $date;
                            $dto->ticker = $symbol;
                            $dto->type = 'BUY';
                            $dto->quantity = abs($quantity);
                            $dto->pricePerUnit = $price;
                            $dto->totalAmount = abs($czkAmount);
                            $dto->currency = 'CZK';
                            $dto->source_broker = 'IBKR';
                            $dto->brokerTradeId = "IBKR_" . md5($date . $symbol . 'BUY' . abs($quantity) . abs($czkAmount));
                            $dto->metadata = [
                                'orig_currency' => $origCurrency,
                                'source' => 'IbkrPdfParser',
                                'raw_line' => substr($line, 0, 300)
                            ];

                            $transactions[] = $dto;
                        }
                    }
                }
                continue;
            }

            // 2) SELL
            if (strpos($line, ' Sell ') !== false) {
                $parts = preg_split('/\s+/', $line);
                $sellIndex = array_search('Sell', $parts);

                if ($sellIndex !== false && $sellIndex > 0 && $sellIndex < count($parts) - 4) {
                    $symbol = $parts[$sellIndex + 1];
                    $quantity = isset($parts[$sellIndex + 2]) ? abs((float)$parts[$sellIndex + 2]) : 0;
                    $price = (float)($parts[$sellIndex + 3] ?? 0);
                    $origCurrency = $parts[$sellIndex + 4] ?? 'USD';

                    $czkAmount = $this->extractLastAmountFromParts($parts);
                    if ($czkAmount !== null) {
                        $czkAmount = abs($czkAmount);
                        $txKey = "{$date}|{$symbol}|Sell|" . number_format($czkAmount, 2, '.', '') . "|" . number_format(abs($quantity), 4, '.', '');
                        if (!in_array($txKey, $processedKeys)) {
                            $processedKeys[] = $txKey;

                            $dto = new TransactionDTO();
                            $dto->date = $date;
                            $dto->ticker = $symbol;
                            $dto->type = 'SELL';
                            $dto->quantity = abs($quantity);
                            $dto->pricePerUnit = $price;
                            $dto->totalAmount = $czkAmount;
                            $dto->currency = 'CZK';
                            $dto->source_broker = 'IBKR';
                            $dto->brokerTradeId = "IBKR_" . md5($date . $symbol . 'SELL' . abs($quantity) . abs($czkAmount));
                            $dto->metadata = [
                                'orig_currency' => $origCurrency,
                                'source' => 'IbkrPdfParser',
                                'raw_line' => substr($line, 0, 300)
                            ];

                            $transactions[] = $dto;
                        }
                    }
                }
                continue;
            }

            // 3) OSTATNÍ (DIVIDEND, TAX, FX, DEPOSIT, CORPORATE ACTION, FEE)
            $transType = $this->detectTransactionType($line);
            if (!$transType) continue;

            $symbol = $this->extractSymbol($line, $transType);
            $netAmount = $this->extractLastAmount($line);
            if ($netAmount === null) continue;

            $txKey = "{$date}|" . ($symbol ?? '') . "|{$transType}|" . number_format(abs($netAmount), 2, '.', '');
            if (in_array($txKey, $processedKeys)) continue;
            $processedKeys[] = $txKey;

            $finalAmount = $netAmount;
            $mappedType = strtoupper(str_replace(' ', '_', $transType));
            if ($mappedType === 'FEE' || $mappedType === 'TAX') {
                $finalAmount = -abs($netAmount);
            } else {
                $finalAmount = abs($netAmount);
            }

            $dto = new TransactionDTO();
            $dto->date = $date;
            $dto->ticker = $symbol ?? ($transType === 'Deposit' ? 'CASH_CZK' : 'FX_PNL');
            $dto->type = $mappedType;
            $dto->quantity = 1;
            $dto->pricePerUnit = $mappedType === 'DIVIDEND' ? abs($netAmount) : 0;
            $dto->totalAmount = abs($finalAmount);
            $dto->currency = 'CZK';
            $dto->source_broker = 'IBKR';
            $dto->fee = $mappedType === 'FEE' ? abs($netAmount) : 0;
            $dto->brokerTradeId = "IBKR_" . md5($date . ($dto->ticker) . $dto->type . abs($finalAmount));
            $dto->metadata = [
                'source' => 'IbkrPdfParser',
                'raw_line' => substr($line, 0, 300)
            ];

            $transactions[] = $dto;
        }

        return $transactions;
    }

    private function fixBrokenDates(array $lines): array {
        $fixedLines = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            if (preg_match('/^(20\d{2}-\d{2})-?$/', $line, $incompleteMatch) && $i + 1 < $count) {
                $nextLine = $lines[$i + 1];
                if (preg_match('/^\d{2}$/', $nextLine)) {
                    if ($i + 2 < $count) {
                        $thirdLine = $lines[$i + 2];
                        $fixed = $incompleteMatch[1] . '-' . $nextLine . ' ' . $thirdLine;
                        $fixedLines[] = $fixed;
                        $i += 3;
                        continue;
                    }
                } elseif (preg_match('/^\d{2}\s/', $nextLine)) {
                    $day = substr($nextLine, 0, 2);
                    $rest = trim(substr($nextLine, 2));
                    $fixed = $incompleteMatch[1] . '-' . $day . ' ' . $rest;
                    $fixedLines[] = $fixed;
                    $i += 2;
                    continue;
                }
            }

            $fixedLines[] = $line;
            $i++;
        }

        return $fixedLines;
    }

    private function mergeLinesByDate(array $lines): array {
        $merged = [];
        $current = '';

        foreach ($lines as $l) {
            $isDate = preg_match('/^20\d{2}-\d{2}-\d{2}\b/', $l);
            if ($isDate) {
                if ($current) {
                    $merged[] = trim($current);
                }
                $current = $l;
            } elseif ($current) {
                $current .= ' ' . $l;
            }
        }
        if ($current) {
            $merged[] = trim($current);
        }
        return $merged;
    }

    private function detectTransactionType(string $text): ?string {
        if (preg_match('/Merged.*Acquisition/i', $text) || preg_match('/Corporate Action/i', $text)) {
            return 'Corporate Action';
        }
        if (preg_match('/FX Translation|P&L Adjustment/i', $text)) {
            return 'FX';
        }
        if (preg_match('/Other Fee|FEE$/i', $text)) {
            return 'Fee';
        }
        if (preg_match('/Cash Transfer.*(?:Deposit|Transfer to)/i', $text)) {
            return 'Deposit';
        }
        if (preg_match('/(?:Foreign Tax|US Tax|JP Tax|Withholding)/i', $text) && !preg_match('/Dividend.*per Share\s*\(Ordinary/i', $text)) {
            return 'Tax';
        }
        if (preg_match('/Cash Dividend.*per Share(?!.*Tax)/i', $text) || preg_match('/Stock Dividend.*Ordinary(?!.*Tax)/i', $text)) {
            return 'Dividend';
        }
        return null;
    }

    private function extractSymbol(string $text, string $transType): ?string {
        if ($transType === 'Deposit') return 'CASH_CZK';
        if ($transType === 'FX') return 'FX_PNL';

        // TICKER (ISIN v závorce)
        if (preg_match('/\b([A-Z][A-Z0-9.\-]{0,9})\s*\([A-Z]{2}[A-Z0-9]{8,10}\)/', $text, $isinMatch)) {
            return $isinMatch[1];
        }

        // fallback – prostý ticker před slovem Dividend/Tax/Fee
        if (preg_match('/\b([A-Z]{2,5})\b(?=.*(?:Dividend|Tax|Fee))/i', $text, $tickerMatch)) {
            $ticker = $tickerMatch[1];
            $excluded = ['USD', 'EUR', 'CZK', 'US', 'JP', 'TAX', 'FEE', 'FOR'];
            if (!in_array(strtoupper($ticker), $excluded)) {
                return $ticker;
            }
        }

        return null;
    }

    private function extractLastAmount(string $text): ?float {
        $amounts = [];
        if (preg_match_all('/[-\d,]+\.\d{2}(?=\s|$)/', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $cleanAmount = str_replace(',', '', $match);
                $num = (float)$cleanAmount;
                if (abs($num) < 10000000) {
                    $amounts[] = $num;
                }
            }
        }
        return count($amounts) > 0 ? $amounts[count($amounts) - 1] : null;
    }

    private function extractLastAmountFromParts(array $parts): ?float {
        for ($j = count($parts) - 1; $j >= 0; $j--) {
            $cleaned = str_replace(',', '', $parts[$j]);
            if (preg_match('/^-?\d+\.\d{2}$/', $cleaned)) {
                $num = (float)$cleaned;
                return $num;
            }
        }
        return null;
    }
}
