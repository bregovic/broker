<?php
namespace Broker\V3\Import\Csv;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Interactive Brokers — Transaction History (CSV).
 *
 * Jiný export než Activity Statement (ten čte IbkrCsvParser). Má jedinou
 * zajímavou sekci `Transaction History` se sloupci:
 *   Date, Account, Description, Transaction Type, Symbol, Quantity, Price,
 *   Price Currency, Gross Amount, Commission, Net Amount
 *
 * **Měny se v tomhle formátu míchají.** `Price` je v měně obchodu
 * (`Price Currency`), ale `Gross Amount`, `Commission` i `Net Amount` jsou
 * v základní měně účtu (`Summary,Data,Base Currency`, typicky CZK).
 * Ověřeno dvěma nezávislými způsoby:
 *   - u obchodů vychází `Gross / (Quantity × Price)` na jednotný denní kurz
 *     (24,040 CZK/USD pro všechny obchody z 2. 12. 2024),
 *   - součet dividend za 2024 dá 2678,9181, což je přesně údaj
 *     „Total **in CZK**" ze stejného období v Activity Statementu.
 *
 * Ukládáme proto částku **v měně obchodu** (`Quantity × Price`), aby v Bilanci
 * fungovaly sloupce v původní měně a rozpad FX P&L. Poplatek přepočítáváme do
 * téže měny kurzem odvozeným z výpisu, takže nemícháme jednotky. Řádky bez
 * `Price Currency` (dividendy, daň, vklady, poplatky) měnu obchodu nemají —
 * u nich se ukládá částka v základní měně tak, jak ji výpis uvádí.
 *
 * Řádky `Forex Trade Component` a `Adjustment` (FX Translations P&L) se
 * **přeskakují**: jsou to účetní artefakty, ne pozice. Kdyby se importovaly,
 * vznikly by z nich tickery typu `EUR.CZK` v portfoliu.
 */
class IbkrTransactionHistoryCsvParser extends AbstractParser {

    private string $zakladniMena = 'CZK';

    public function getName(): string {
        return 'IBKR Transaction History (CSV)';
    }

    public function canParse(string $content, string $filename): bool {
        $zacatek = substr($content, 0, 4000);
        return str_contains($zacatek, 'Transaction History,Header,Date')
            || str_contains($zacatek, 'Statement,Data,Title,Transaction History');
    }

    public function parse(string $content): array {
        $transakce = [];
        $hlavicka = null;

        foreach (preg_split('/\r\n|\n|\r/', $content) as $radek) {
            if ($radek === '') continue;

            $bunky = str_getcsv($radek);
            if (count($bunky) < 3) continue;

            $sekce = trim($bunky[0], "\xEF\xBB\xBF \t");
            $typRadku = $bunky[1];

            if ($sekce === 'Summary' && $typRadku === 'Data' && ($bunky[2] ?? '') === 'Base Currency') {
                $mena = strtoupper(trim($bunky[3] ?? ''));
                if ($mena !== '') $this->zakladniMena = $mena;
                continue;
            }

            if ($sekce !== 'Transaction History') continue;

            if ($typRadku === 'Header') {
                // "Gross Amount " má v hlavičce mezeru navíc — názvy proto trimujeme.
                $hlavicka = array_map(fn($n) => trim($n), array_slice($bunky, 2));
                continue;
            }
            if ($typRadku !== 'Data' || $hlavicka === null) continue;

            // IBKR míchá mezi data i souhrnné řádky (viz IbkrCsvParser).
            if (preg_match('/^Total\b/i', trim($bunky[2] ?? ''))) continue;

            $t = $this->radek($this->naPole($hlavicka, array_slice($bunky, 2)));
            if ($t) $transakce[] = $t;
        }

        return $transakce;
    }

    /** Spojí hlavičku s hodnotami; "-" je v tomhle exportu prázdná hodnota. */
    private function naPole(array $hlavicka, array $hodnoty): array {
        $out = [];
        foreach ($hlavicka as $i => $nazev) {
            $h = trim($hodnoty[$i] ?? '');
            $out[$nazev] = ($h === '-') ? '' : $h;
        }
        return $out;
    }

    private function radek(array $r): ?TransactionDTO {
        $druh = trim($r['Transaction Type'] ?? '');
        $datum = $this->datum($r['Date'] ?? '');
        if (!$datum) return null;

        switch ($druh) {
            case 'Buy':
            case 'Sell':
                return $this->obchod($r, $datum, $druh === 'Sell' ? 'SELL' : 'BUY');

            case 'Dividend':          return $this->vZakladniMene($r, $datum, 'DIVIDEND');
            case 'Foreign Tax Withholding':
            case 'Withholding Tax':   return $this->vZakladniMene($r, $datum, 'TAX');
            case 'Other Fee':
            case 'Commission Adjustment':
            case 'Fee':               return $this->vZakladniMene($r, $datum, 'FEE');
            case 'Deposit':           return $this->vZakladniMene($r, $datum, 'DEPOSIT');
            case 'Withdrawal':        return $this->vZakladniMene($r, $datum, 'WITHDRAWAL');
            case 'Corporate Action':  return $this->vZakladniMene($r, $datum, 'OTHER');

            // Účetní artefakty, ne pozice — viz komentář u třídy.
            case 'Forex Trade Component':
            case 'Adjustment':
            default:
                return null;
        }
    }

    private function obchod(array $r, string $datum, string $typ): ?TransactionDTO {
        $ticker = trim($r['Symbol'] ?? '');
        if ($ticker === '') return null;

        $mnozstvi = abs($this->parseNumber($r['Quantity'] ?? '') ?? 0);
        $cena     = abs($this->parseNumber($r['Price'] ?? '') ?? 0);
        if ($mnozstvi == 0.0 || $cena == 0.0) return null;

        $mena = strtoupper(trim($r['Price Currency'] ?? '')) ?: $this->zakladniMena;
        $vMeneObchodu = $mnozstvi * $cena;

        // Kurz, který IBKR na obchod skutečně použil, si odvodíme z výpisu:
        // Gross je v základní měně, Quantity × Price v měně obchodu.
        $vZakladni = abs($this->parseNumber($r['Gross Amount'] ?? '') ?? 0);
        $kurz = ($vZakladni > 0 && $vMeneObchodu > 0) ? $vZakladni / $vMeneObchodu : 0.0;

        // Commission je v základní měně; převedeme ji do měny obchodu, ať se
        // v jedné transakci nemíchají jednotky.
        $poplatek = abs($this->parseNumber($r['Commission'] ?? '') ?? 0);
        if ($kurz > 0) $poplatek = $poplatek / $kurz;

        $t = $this->novy($typ, $r, $datum);
        $t->ticker       = $ticker;
        $t->quantity     = $mnozstvi;
        $t->pricePerUnit = $cena;
        $t->currency     = $mena;
        $t->totalAmount  = $vMeneObchodu;
        $t->fee          = round($poplatek, 6);
        $t->metadata     = [
            'description' => $r['Description'] ?? '',
            'base_amount' => $vZakladni,
            'base_currency' => $this->zakladniMena,
            'implied_rate' => $kurz ? round($kurz, 6) : null,
        ];
        return $t;
    }

    /**
     * Řádky bez měny obchodu — výpis u nich uvádí částku jen v základní měně,
     * tak ji tak i uložíme.
     */
    private function vZakladniMene(array $r, string $datum, string $typ): ?TransactionDTO {
        $castka = $this->parseNumber($r['Gross Amount'] ?? '') ?? 0;
        if ($castka == 0.0) $castka = $this->parseNumber($r['Net Amount'] ?? '') ?? 0;
        if ($castka == 0.0) return null;

        $t = $this->novy($typ, $r, $datum);
        $t->ticker      = trim($r['Symbol'] ?? '') ?: null;
        $t->quantity    = 0;
        $t->currency    = $this->zakladniMena;
        $t->totalAmount = ($typ === 'TAX' || $typ === 'FEE') ? $castka : abs($castka);
        if ($typ === 'FEE') $t->fee = abs($castka);
        $t->metadata    = ['description' => $r['Description'] ?? ''];
        return $t;
    }

    private function novy(string $typ, array $r, string $datum): TransactionDTO {
        $t = new TransactionDTO();
        $t->type = $typ;
        $t->date = $datum;
        $t->source_broker = 'IBKR';
        // Stabilní napříč exporty: dva výpisy se překrývajícím obdobím obsahují
        // tentýž řádek a musí se odbourat jako duplicita.
        //
        // Commission musí být součástí otisku! Jeden příkaz se u IBKR plní po
        // částech a dílčí fily se liší *jenom* rozpadem provize — 2× INTC 15 ks
        // se shodným datem, cenou i objemem, ale poplatkem 24,07 a 0,03 CZK.
        // Bez ní by druhý fill zmizel jako duplicita a z 30 kusů zůstalo 15.
        $t->brokerTradeId = 'IBKR_TH_' . md5(implode('|', [
            $datum,
            trim($r['Symbol'] ?? ''),
            $typ,
            trim($r['Quantity'] ?? ''),
            trim($r['Price'] ?? ''),
            trim($r['Gross Amount'] ?? ''),
            trim($r['Commission'] ?? ''),
            trim($r['Net Amount'] ?? ''),
            trim($r['Description'] ?? ''),
        ]));
        return $t;
    }

    /** `2026-08-14` i `2026-08-14, 09:30:00` → `2026-08-14`. */
    private function datum(string $hodnota): ?string {
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', trim($hodnota), $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        return null;
    }
}
