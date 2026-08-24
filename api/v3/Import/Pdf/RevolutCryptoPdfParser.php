<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Revolut crypto account statement (PDF).
 *
 * Layout note: the date is the LAST column of every row, not the first —
 *   Transakce:        Symbol Typ Množství Cena Hodnota Poplatky Datum
 *   Odměny ze stakingu: Symbol Typ Množství Datum
 * so rows are matched whole, anchored on the symbol+type at the start and the
 * date at the end. Splitting the text into date-led blocks (as this parser used
 * to do) assigns every row the *previous* row's date.
 */
class RevolutCryptoPdfParser extends AbstractParser {

    /** currency token: symbol or ISO code (statements also carry CNY, USD, EUR) */
    private const CUR  = '(€|\$|[A-Z]{3})';
    /** a number, possibly with spaces as thousands separators ("1 151 675,69") */
    private const NUM  = '([0-9][0-9.,\s]*?)';
    private const DATE = '(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})';

    public function getName(): string {
        return "Revolut Crypto PDF";
    }

    public function canParse(string $content, string $filename): bool {
        return (bool)preg_match('/Výpis z účtu s krypto|Crypto\s+Account\s+Statement|Revolut Digital Assets|Odm[ěĕ]na za staking/ui', $content);
    }

    public function parse(string $content): array {
        $t = str_replace("\xc2\xa0", ' ', $content);
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);
        $t = trim($t);

        $out = [];

        // --- Trades, and card payments made in crypto -------------------------------
        // "ETH Nákup 1,22735925 4 073,79 CZK 5 000,00 CZK 0,00 CZK 18. 11. 2018"
        //  symbol type  quantity  unit price   total value   fee        date
        $tradeRe = '/\b([A-Z]{2,10})\s+(Nákup|Prodej|Buy|Sell|Platba|Payment)\s+'
                 . self::NUM . '\s+'
                 . self::NUM . '\s*' . self::CUR . '\s+'   // unit price (derived from total, unused)
                 . self::NUM . '\s*' . self::CUR . '\s+'   // total value  <- the one we keep
                 . self::NUM . '\s*' . self::CUR . '\s+'   // fee
                 . self::DATE . '/u';

        if (preg_match_all($tradeRe, $t, $trades, PREG_SET_ORDER)) {
            foreach ($trades as $m) {
                // A card payment settled in crypto is a disposal, same as a sale.
                $type = preg_match('/^(Prodej|Sell|Platba|Payment)$/ui', $m[2]) ? 'SELL' : 'BUY';
                $out[] = $this->createTransaction(
                    $this->csDateToISO($m[10]),
                    strtoupper($m[1]),
                    $type,
                    $this->parseNumber($m[3]),
                    $this->parseNumber($m[6]),
                    $this->symToFiat($m[7]),
                    $this->parseNumber($m[8]),
                    'Revolut crypto: ' . $m[2]
                );
            }
        }

        // --- Staking rewards ---------------------------------------------------------
        // "ADA Odměna za staking 0,263607 2. 12. 2024"
        $stakeRe = '/\b([A-Z]{2,10})\s+(?:Odm[ěĕ]na za staking|Staking rewards?)\s+([0-9][0-9.,]*)\s+'
                 . self::DATE . '/u';

        if (preg_match_all($stakeRe, $t, $stakes, PREG_SET_ORDER)) {
            foreach ($stakes as $m) {
                $out[] = $this->createTransaction(
                    $this->csDateToISO($m[3]),
                    strtoupper($m[1]),
                    'REVENUE',
                    $this->parseNumber($m[2]),
                    0,
                    'CZK',
                    0,
                    'Staking reward'
                );
            }
        }

        return $out;
    }

    private function symToFiat(string $s): string {
        if ($s === '$') return 'USD';
        if ($s === '€') return 'EUR';
        return strtoupper($s) ?: 'CZK';
    }

    private function createTransaction($date, $ticker, $type, $qty, $total, $currency, $fee = 0, $notes = ''): TransactionDTO {
        $dto = new TransactionDTO();
        $dto->date = $date;
        $dto->ticker = $ticker;
        $dto->type = $type;
        $dto->quantity = (float)$qty;
        $dto->pricePerUnit = (float)($qty ? abs($total / $qty) : 0);
        $dto->currency = $currency;
        $dto->fee = (float)$fee;
        $dto->totalAmount = (float)$total;
        $dto->source_broker = 'Revolut';
        $dto->metadata = ['notes' => $notes, 'source' => 'RevolutCryptoPdfParser'];
        $dto->brokerTradeId = "REV_CRY_" . md5($date . $ticker . $type . $qty . $total);
        return $dto;
    }
}
