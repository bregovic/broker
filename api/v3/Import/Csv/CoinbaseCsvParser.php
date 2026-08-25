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
 *
 * ## Proč `Total` a ne `Subtotal`
 *
 * Coinbase uvádí u obchodu tři čísla a **žádná dvě nesedí na třetí**. U prodeje
 * 0,1 BTC z 21. 11. 2024 je Subtotal 231 968,45, Total 228 512,09 a vykázaný
 * poplatek jen 2 343,15 — rozdíl 3 456,36 Kč tedy poplatek nevysvětluje celý,
 * zbytek je spread. Kdybychom ukládali Subtotal a poplatek zvlášť, vyšel by nám
 * čistý výnos 229 625 Kč, ačkoli na účet skutečně přišlo 228 512 Kč.
 *
 * Ukládá se proto `Total` — to je částka, která reálně odešla nebo přišla —
 * a poplatek se už podruhé neodečítá (`fee = 0`). Rozklad na vykázaný poplatek,
 * celkový execution cost a nevysvětlený zbytek zůstává v metadatech.
 *
 * ## Proč převod nesmí založit pořizovací cenu
 *
 * `Pro Withdrawal` z 30. 11. 2022 přinesl 1,22992676 BTC z Coinbase Pro. Výpis
 * u něj uvádí tehdejší tržní hodnotu 485 670,87 Kč, jenže to je jen ocenění
 * převodu — nakoupeno bylo dřív a jinde. Brát to jako nákup znamenalo ocenit
 * bitcoin na 394 878 Kč/BTC, tedy zhruba dnem cyklického minima, a nadhodnotit
 * realizovaný zisk roku 2024 o víc než 400 000 Kč.
 *
 * Převod se proto ukládá s nulovou pořizovací cenou a `basis_status = UNKNOWN`.
 * Skutečnou cenu dopočítá `api/v3/cost_basis.php` z peněz poslaných na burzu;
 * dokud se to nepovede, aplikace u té pozice zisk nevyčísluje.
 *
 * Interní přesuny peněz (`Exchange Deposit` / `Exchange Withdrawal`) se ukládají
 * jako `INTERNAL` — nejsou to vklady zvenčí, ale právě ony prozrazují, kolik
 * peněz na burzu odešlo, takže je dopočet pořizovací ceny potřebuje.
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
        $mnozstvi = abs((float)$this->cislo($r['Quantity Transacted'] ?? ''));
        $zaporne = (float)$this->cislo($r['Quantity Transacted'] ?? '') < 0;
        $mena = strtoupper(trim($r['Price Currency'] ?? '')) ?: 'CZK';
        $fiat = in_array($aktivum, self::FIAT, true);

        // Ekonomicky rozhoduje Total; Subtotal je hodnota před poplatkem a spreadem.
        $subtotal = abs((float)$this->cislo($r['Subtotal'] ?? ''));
        $total    = abs((float)$this->cislo($r['Total (inclusive of fees and/or spread)'] ?? ''));
        $hodnota  = $total > 0 ? $total : $subtotal;

        switch ($druh) {
            case 'Exchange Deposit':      // přesun peněz mezi Coinbase a Coinbase Pro
            case 'Exchange Withdrawal':
                // Není to pohyb kapitálu zvenčí, ale bez něj nelze dopočítat
                // pořizovací cenu kryptoměny, která se z burzy vrátila.
                return $this->dto('INTERNAL', $datum, null, 0.0, $hodnota, $mena, $r,
                    ['smer' => $zaporne ? 'na_burzu' : 'z_burzy']);

            case 'Buy':
                return $this->dto('BUY', $datum, $aktivum, $mnozstvi, $hodnota, $mena, $r);

            case 'Sell':
                return $this->dto('SELL', $datum, $aktivum, $mnozstvi, $hodnota, $mena, $r);

            case 'Retail Simple Price Improvement':
                // Drobný dobropis připsaný v aktivu — vzniká skutečná pozice,
                // takže i tady patří hodnota z výpisu, ne nula.
                return $this->dto('REVENUE', $datum, $aktivum, $mnozstvi, $hodnota, $mena, $r);

            case 'Deposit':
            case 'Withdrawal':
            case 'Pro Deposit':
            case 'Pro Withdrawal':
            case 'Send':
            case 'Receive':
                if ($fiat) {
                    // Hotovost: ticker nechává import doplnit jako CASH_<měna>.
                    return $this->dto($zaporne ? 'WITHDRAWAL' : 'DEPOSIT',
                        $datum, null, 0.0, $hodnota, $mena, $r);
                }
                /*
                 * Převod kryptoměny mění pozici, ale nezakládá pořizovací cenu —
                 * ta zůstala u obchodu na zdrojovém účtu. Hodnota jde do metadat
                 * jako tržní ocenění, do částky nula a stav `UNKNOWN`.
                 */
                return $this->dto($zaporne ? 'SELL' : 'BUY',
                    $datum, $aktivum, $mnozstvi, 0.0, $mena, $r, [
                        'prevod' => true,
                        'flow_type' => 'INTERNAL',
                        'basis_status' => 'UNKNOWN',
                        'trzni_hodnota' => round($hodnota, 5),
                    ]);

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
                         float $hodnota, string $mena, array $r, array $navic = []): TransactionDTO {

        $subtotal = abs((float)$this->cislo($r['Subtotal'] ?? ''));
        $total    = abs((float)$this->cislo($r['Total (inclusive of fees and/or spread)'] ?? ''));
        $vykazany = abs((float)$this->cislo($r['Fees and/or Spread'] ?? ''));
        // Co obchod doopravdy stál: rozdíl mezi hodnotou obchodu a tím, co se
        // skutečně pohnulo na účtu. Vykázaný poplatek bývá jen jeho část.
        $execution = ($subtotal > 0 && $total > 0) ? abs($subtotal - $total) : 0.0;

        $meta = array_merge([
            'coinbase_typ' => $r['Transaction Type'] ?? '',
            'poznamka' => mb_substr(trim((string)($r['Notes'] ?? '')), 0, 140),
            'subtotal' => round($subtotal, 5),
            'total' => round($total, 5),
            'vykazany_poplatek' => round($vykazany, 5),
            'execution_cost' => round($execution, 5),
            'nevysvetleny_naklad' => round(max(0.0, $execution - $vykazany), 5),
            'trzni_cena' => (float)($this->cislo($r['Price at Transaction'] ?? '') ?? 0),
        ], $navic);

        $t = new TransactionDTO();
        $t->type = $typ;
        $t->date = $datum;
        $t->ticker = $ticker;
        $t->quantity = $mnozstvi;
        $t->pricePerUnit = $mnozstvi > 0 ? abs($hodnota / $mnozstvi) : 0.0;
        $t->currency = $mena;
        // Částka už poplatek i spread obsahuje (viz komentář u třídy), takže se
        // nesmí odečíst znovu. Rozpad je v metadatech.
        $t->fee = 0.0;
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
