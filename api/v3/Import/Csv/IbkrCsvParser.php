<?php
namespace Broker\V3\Import\Csv;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Interactive Brokers — Activity Statement (CSV).
 *
 * Formát není klasická tabulka: soubor obsahuje víc sekcí za sebou a každý
 * řádek začíná názvem sekce a typem řádku (`Header` / `Data`). Sloupce se tedy
 * musí číst podle hlavičky **té které sekce**, ne podle pořadí v souboru.
 *
 * Zpracováváme pět sekcí:
 *   Trades                — nákupy a prodeje (typ podle znaménka množství)
 *   Dividends             — dividendy
 *   Withholding Tax       — srážková daň
 *   Fees                  — poplatky
 *   Deposits & Withdrawals— vklady a výběry
 *
 * Proti PDF je tohle spolehlivý zdroj: ticker, poplatek i měna jsou vlastní
 * sloupce, nemusí se nic dohadovat z textu.
 */
class IbkrCsvParser extends AbstractParser {

    public function getName(): string {
        return 'IBKR Activity Statement (CSV)';
    }

    public function canParse(string $content, string $filename): bool {
        // Výpis se pozná podle vlastní hlavičky, ne podle názvu souboru —
        // ten si každý pojmenuje jinak.
        $zacatek = substr($content, 0, 4000);
        return str_contains($zacatek, 'Statement,Header,Field Name')
            || (str_contains($zacatek, 'Trades,Header,DataDiscriminator')
                && str_contains($zacatek, 'Asset Category'));
    }

    public function parse(string $content): array {
        $transakce = [];
        $hlavicky = [];   // název sekce => seznam sloupců

        foreach (preg_split('/\r\n|\n|\r/', $content) as $radek) {
            if ($radek === '') continue;

            $bunky = str_getcsv($radek);
            if (count($bunky) < 3) continue;

            $sekce = trim($bunky[0], "\xEF\xBB\xBF \t");
            $typRadku = $bunky[1];

            if ($typRadku === 'Header') {
                // Hlavička platí pro následující řádky téže sekce.
                $hlavicky[$sekce] = array_slice($bunky, 2);
                continue;
            }
            if ($typRadku !== 'Data' || !isset($hlavicky[$sekce])) continue;

            // IBKR mixes subtotals into the data rows of every section and labels them
            // in the first data cell ("Total", "Total in CZK", "Total Deposits &
            // Withdrawals in CZK"). Without this they import as real transactions —
            // phantom deposits, dividends and fees, each counted a second time.
            if (preg_match('/^Total\b/i', trim($bunky[2] ?? ''))) continue;

            $r = $this->naPole($hlavicky[$sekce], array_slice($bunky, 2));

            switch ($sekce) {
                case 'Trades':              $t = $this->obchod($r);   break;
                case 'Dividends':           $t = $this->dividenda($r); break;
                case 'Withholding Tax':     $t = $this->dan($r);      break;
                case 'Fees':                $t = $this->poplatek($r); break;
                case 'Deposits & Withdrawals': $t = $this->prevod($r); break;
                default:                    $t = null;
            }

            if ($t) $transakce[] = $t;
        }

        return $transakce;
    }

    /** Spojí hlavičku s hodnotami; chybějící sloupce nevadí. */
    private function naPole(array $hlavicka, array $hodnoty): array {
        $out = [];
        foreach ($hlavicka as $i => $nazev) {
            $out[trim($nazev)] = $hodnoty[$i] ?? '';
        }
        return $out;
    }

    private function novy(string $typ, array $r): TransactionDTO {
        $t = new TransactionDTO();
        $t->type = $typ;
        $t->source_broker = 'IBKR';
        $t->currency = trim($r['Currency'] ?? 'USD') ?: 'USD';
        return $t;
    }

    /**
     * Obchod. Sekce Trades se v souboru objevuje víckrát — jednou po řádcích
     * příkazů, jednou jako souhrn. Bereme jen `Order`, jinak by se každý
     * obchod započítal dvakrát.
     */
    private function obchod(array $r): ?TransactionDTO {
        if (($r['DataDiscriminator'] ?? '') !== 'Order') return null;
        if (($r['Asset Category'] ?? '') === '') return null;
        if (trim($r['Symbol'] ?? '') === '') return null;

        $mnozstvi = $this->parseNumber($r['Quantity'] ?? '0') ?? 0;
        if ($mnozstvi == 0) return null;

        // IBKR nemá sloupec Buy/Sell — pozná se podle znaménka množství.
        $t = $this->novy($mnozstvi > 0 ? 'BUY' : 'SELL', $r);
        $t->ticker       = trim($r['Symbol']);
        $t->date         = $this->datum($r['Date/Time'] ?? '');
        $t->quantity     = abs($mnozstvi);
        $t->pricePerUnit = abs($this->parseNumber($r['T. Price'] ?? '0') ?? 0);
        // Comm/Fee je záporné (poplatek se strhává), do evidence patří kladně.
        $t->fee          = abs($this->parseNumber($r['Comm/Fee'] ?? '0') ?? 0);
        $t->totalAmount  = abs($this->parseNumber($r['Proceeds'] ?? '0') ?? 0);
        $t->metadata     = ['asset_category' => $r['Asset Category'] ?? ''];

        return $t;
    }

    private function dividenda(array $r): ?TransactionDTO {
        $castka = $this->parseNumber($r['Amount'] ?? '0') ?? 0;
        if ($castka == 0) return null;

        $t = $this->novy('DIVIDEND', $r);
        $t->ticker      = $this->tickerZPopisu($r['Description'] ?? '');
        $t->date        = $this->datum($r['Date'] ?? '');
        $t->totalAmount = $castka;
        $t->quantity    = 0;
        $t->metadata    = ['description' => $r['Description'] ?? ''];

        return $t;
    }

    private function dan(array $r): ?TransactionDTO {
        $castka = $this->parseNumber($r['Amount'] ?? '0') ?? 0;
        if ($castka == 0) return null;

        $t = $this->novy('TAX', $r);
        $t->ticker      = $this->tickerZPopisu($r['Description'] ?? '');
        $t->date        = $this->datum($r['Date'] ?? '');
        $t->totalAmount = $castka; // záporná částka = sražená daň
        $t->metadata    = ['description' => $r['Description'] ?? ''];

        return $t;
    }

    private function poplatek(array $r): ?TransactionDTO {
        $castka = $this->parseNumber($r['Amount'] ?? '0') ?? 0;
        if ($castka == 0) return null;

        $t = $this->novy('FEE', $r);
        $t->date        = $this->datum($r['Date'] ?? '');
        $t->totalAmount = $castka;
        $t->fee         = abs($castka);
        $t->metadata    = ['description' => $r['Description'] ?? ''];

        return $t;
    }

    private function prevod(array $r): ?TransactionDTO {
        $castka = $this->parseNumber($r['Amount'] ?? '0') ?? 0;
        if ($castka == 0) return null;

        $t = $this->novy($castka > 0 ? 'DEPOSIT' : 'WITHDRAWAL', $r);
        $t->date        = $this->datum($r['Settle Date'] ?? '');
        $t->totalAmount = abs($castka);
        $t->metadata    = ['description' => $r['Description'] ?? ''];

        return $t;
    }

    /**
     * Ticker z popisu dividendy nebo daně. IBKR ho píše na začátek, obvykle
     * ve tvaru `AAPL(US0378331005) Cash Dividend ...`.
     */
    private function tickerZPopisu(string $popis): ?string {
        if (preg_match('/^([A-Z0-9.\-]{1,10})\s*\(/', trim($popis), $m)) return $m[1];
        if (preg_match('/^([A-Z]{1,5})\s/', trim($popis), $m)) return $m[1];
        return null;
    }

    /** `2024-03-15, 09:30:00` i `2024-03-15` → `2024-03-15`. */
    private function datum(string $hodnota): ?string {
        $hodnota = trim($hodnota);
        if ($hodnota === '') return null;
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $hodnota, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        return null;
    }
}
