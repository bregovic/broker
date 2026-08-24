<?php
namespace Broker\V3\Import\Csv;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Coinbase — roční výpis transakcí (CSV).
 *
 * Soubor má pár úvodních řádků a teprve pak hlavičku:
 *   ID,Timestamp,Transaction Type,Asset,Quantity Transacted,Price Currency,
 *   Price at Transaction,Subtotal,Total (inclusive of fees and/or spread),
 *   Fees and/or Spread,Notes,Sender Address,Recipient Address
 *
 * Částky mají před sebou symbol měny („Kč16233.43638“, „-Kč24741.93908“) a jsou
 * v anglickém formátu s tečkou; měna je vlastní sloupec `Price Currency`.
 * Ukládá se `Subtotal` (hodnota obchodu) a poplatek zvlášť — stejně jako
 * u ostatních parserů, aby `api-pnl.php` počítalo konzistentně.
 *
 * **Převody nejsou obchod, ale pozici mění.** `Pro Withdrawal` je příchod kryptoměny
 * z Coinbase Pro; ve vzorku přinesl 1,2299 BTC, které se pak postupně prodalo.
 * Kdyby se přeskočil, chybělo by v portfoliu přesně tolik. Bere se proto jako
 * nákup v hodnotě, kterou výpis uvádí — skutečnou pořizovací cenu z Coinbase Pro
 * tenhle výpis neobsahuje, takže je to nejbližší dostupný odhad a v metadatech
 * je to označené (`prevod` => true).
 *
 * Přeskakuje se `Exchange Deposit` / `Exchange Withdrawal` — to je jen přesun
 * peněz mezi Coinbase a Coinbase Pro, ne pohyb pozice.
 */
class CoinbaseCsvParser extends AbstractParser {

    /** Fiat měny — jejich vklad a výběr je pohyb hotovosti, ne pozice. */
    private const FIAT = ['EUR', 'USD', 'CZK', 'GBP', 'CHF', 'PLN'];

    public function getName(): string {
        // Pozor: `api-import.php` odvozuje product_type mimo jiné z názvu parseru
        // — slovo „Crypto“ zajistí, že se transakce nezaloží jako akcie.
        return 'Coinbase Crypto (CSV)';
    }

    public function canParse(string $content, string $filename): bool {
        $zacatek = substr($content, 0, 3000);
        return str_contains($zacatek, 'Quantity Transacted')
            && str_contains($zacatek, 'Price at Transaction')
            && str_contains($zacatek, 'Transaction Type');
    }

    public function parse(string $content): array {
        $out = [];
        $hlavicka = null;

        foreach (preg_split('/\r\n|\n|\r/', $content) as $radek) {
            if (trim($radek) === '') continue;
            $bunky = str_getcsv($radek);
            if (!$bunky) continue;

            if (($bunky[0] ?? '') === 'ID' && in_array('Transaction Type', $bunky, true)) {
                $hlavicka = array_map(fn($h) => trim($h), $bunky);
                continue;
            }
            if ($hlavicka === null) continue;
            // Úvodní řádky „Transactions“ a „User,...“ hlavičce předcházejí.
            if (in_array($bunky[0] ?? '', ['', 'Transactions', 'User'], true)) continue;

            $t = $this->radek(array_combine(
                $hlavicka,
                array_pad(array_slice($bunky, 0, count($hlavicka)), count($hlavicka), '')
            ));
            if ($t) $out[] = $t;
        }
        return $out;
    }

    private function radek(array $r): ?TransactionDTO {
        $datum = $this->datum($r['Timestamp'] ?? '');
        if (!$datum) return null;

        $druh = trim($r['Transaction Type'] ?? '');
        $aktivum = strtoupper(trim($r['Asset'] ?? ''));
        $mnozstvi = $this->cislo($r['Quantity Transacted'] ?? '');
        $mena = strtoupper(trim($r['Price Currency'] ?? '')) ?: 'CZK';
        $hodnota = abs((float)$this->cislo($r['Subtotal'] ?? ''));
        if ($hodnota == 0.0) $hodnota = abs((float)$this->cislo($r['Total (inclusive of fees and/or spread)'] ?? ''));
        $poplatek = abs((float)$this->cislo($r['Fees and/or Spread'] ?? ''));
        $fiat = in_array($aktivum, self::FIAT, true);

        switch ($druh) {
            case 'Exchange Deposit':      // přesun mezi Coinbase a Coinbase Pro
            case 'Exchange Withdrawal':
                return null;

            case 'Buy':
                return $this->dto('BUY', $datum, $aktivum, abs((float)$mnozstvi), $hodnota, $mena, $poplatek, $r);

            case 'Sell':
                return $this->dto('SELL', $datum, $aktivum, abs((float)$mnozstvi), $hodnota, $mena, $poplatek, $r);

            case 'Retail Simple Price Improvement':
                // Drobný dobropis připsaný v aktivu, ne nákup.
                return $this->dto('REVENUE', $datum, $aktivum, abs((float)$mnozstvi), 0.0, $mena, 0.0, $r);

            case 'Deposit':
            case 'Withdrawal':
            case 'Pro Deposit':
            case 'Pro Withdrawal':
            case 'Send':
            case 'Receive':
                if ($fiat) {
                    // Hotovost: ticker nechává import doplnit jako CASH_<měna>.
                    return $this->dto((float)$mnozstvi < 0 ? 'WITHDRAWAL' : 'DEPOSIT',
                        $datum, null, 0.0, $hodnota, $mena, 0.0, $r);
                }
                // Převod kryptoměny mění pozici, i když to není obchod.
                return $this->dto((float)$mnozstvi < 0 ? 'SELL' : 'BUY',
                    $datum, $aktivum, abs((float)$mnozstvi), $hodnota, $mena, $poplatek, $r, true);

            default:
                return null;
        }
    }

    /** „2026-02-05 00:24:47 UTC“ → „2026-02-05“. */
    private function datum(string $s): ?string {
        return preg_match('/(\d{4})-(\d{2})-(\d{2})/', trim($s), $m) ? "$m[1]-$m[2]-$m[3]" : null;
    }

    /** Odstraní symbol měny; formát je anglický, tečka je desetinná. */
    private function cislo(?string $s): ?float {
        if ($s === null) return null;
        $v = preg_replace('/[^0-9.\-]/u', '', $s);
        return ($v === '' || $v === '-') ? null : (float)$v;
    }

    private function dto(string $typ, string $datum, ?string $ticker, float $mnozstvi,
                         float $hodnota, string $mena, float $poplatek, array $r,
                         bool $prevod = false): TransactionDTO {
        $meta = [
            'coinbase_typ' => $r['Transaction Type'] ?? '',
            'poznamka' => mb_substr(trim((string)($r['Notes'] ?? '')), 0, 140),
        ];
        // U převodu není v tomhle výpisu skutečná pořizovací cena; uložená hodnota
        // je tržní v okamžiku převodu, což je odhad — ať je to v datech vidět.
        if ($prevod) $meta['prevod'] = true;

        $t = new TransactionDTO();
        $t->type = $typ;
        $t->date = $datum;
        $t->ticker = $ticker;
        $t->quantity = $mnozstvi;
        $t->pricePerUnit = $mnozstvi > 0 ? abs($hodnota / $mnozstvi) : 0.0;
        $t->currency = $mena;
        $t->fee = $poplatek;
        $t->totalAmount = $hodnota;
        $t->source_broker = 'Coinbase';
        $t->metadata = $meta;
        // Coinbase dává vlastní ID řádku — spolehlivější otisk než hash hodnot.
        $id = trim((string)($r['ID'] ?? ''));
        $t->brokerTradeId = 'CB_' . ($id !== ''
            ? $id
            : md5(implode('|', [$datum, (string)$ticker, $typ, $mnozstvi, $hodnota, $mena])));
        return $t;
    }
}
