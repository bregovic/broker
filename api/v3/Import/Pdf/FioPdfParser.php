<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Fio banka — výpis z obchodního účtu (PDF).
 *
 * Fio formát výpisu za běhu dvakrát změnilo. Rozlišují se spolehlivě podle
 * názvu sloupce v souhrnu, ten se měnil spolu se strukturou:
 *
 *   GENERACE 1  „Výnosy z CP“  (cenné papíry)   — vzorky 2020-01 až 2021-01
 *       Řádek: DATUM ČAS | Objem | Poplatky | Cena | Množství | Směr Trh Název
 *       Datum ve tvaru `05.01.'21 10:14`, sloupce jdou v PDF v obráceném pořadí
 *       a chybí ISIN.
 *
 *   GENERACE 2  „Výnosy z IN“  (investiční nástroje) — 2021-02 až dosud
 *       Řádek: DATUM ČAS | Název | ISIN | Množství | Objem | Trh | Operace |
 *              Jednotková cena | ID operace | Upřesnění | Poplatky | Podíl PNO
 *       Datum ve tvaru `2.1.2025 10:26`, přibyl ISIN a ID operace.
 *
 * Pozdější výpisy generace 2 mají na titulní straně navíc „Spisová značka“,
 * ale **tabulka operací je totožná**, takže je čte tentýž kód. (Ověřeno:
 * 2024-01 existuje ve dvou staženích — starším bez spisové značky a novějším
 * s ní — a obě mají identickou hlavičku tabulky.)
 *
 * Měna se bere z nadpisu sekce `Výpis operací v <MĚNA>`; jeden výpis jich má
 * víc (CZK i EUR). Řádky, kde je „název papíru“ ve skutečnosti kód měny, jsou
 * převody mezi měnami — přeskakují se, aby v portfoliu nevznikly tickery
 * jako `EUR`.
 */
class FioPdfParser extends AbstractParser {

    /** ISIN → ticker; převzato z api/js/data/TickerMap.js (mrtvá JS větev). */
    private const ISIN_TICKER = [
        'CZ0009008942' => 'CZG',   'CZ0005112300' => 'CEZ',   'CZ1008000310' => 'DSPW',
        'AT0000652011' => 'ERBAG', 'SK1000025322' => 'GEV',   'CZ0009000121' => 'KOFOL',
        'CZ0008019106' => 'KOMB',  'CZ0008040318' => 'MONET', 'CZ0005135970' => 'PRIUA',
        'SK1120010287' => 'TMR',   'AT0000908504' => 'VIG',   'CZ0005123620' => 'EFORU',
        'CS0008419750' => 'ENRGA', 'CZ0009011474' => 'FTSHP', 'CS0008418869' => 'TABAK',
        'NL0010391108' => 'PEN',   'CS0008416251' => 'PVT',   'CZ0009009940' => 'SABFG',
        'CZ0005088559' => 'TOMA',  'CZ0009004792' => 'ATOMT', 'CZ0009011920' => 'BEZVA',
        'CZ0009010823' => 'COLOS', 'CZ0009009718' => 'EMAN',  'CZ0009007027' => 'FILL',
        'CZ0009011086' => 'FIXED', 'CZ0005138529' => 'HWIO',  'CZ0009008819' => 'KARIN',
        'CZ0009011714' => 'KLIKY', 'CZ1008000823' => 'M2C',   'CZ0005138826' => 'MMCTE',
        'CZ0009009874' => 'PINK',  'CZ0005131318' => 'PRAB',  'NL00150006R6' => 'CTP',
        'CZ0009093209' => 'O2',
    ];

    /** Název → ticker. Fio píše názvy různě, proto se hledá na začátku názvu. */
    private const NAZEV_TICKER = [
        'COLT CZ GROUP' => 'CZG',   'ČEZ' => 'CEZ',            'DOOSAN ŠKODA POWER' => 'DSPW',
        'ERSTE GROUP BANK' => 'ERBAG', 'GEVORKYAN' => 'GEV',   'KOFOLA' => 'KOFOL',
        'KOMERČNÍ BANKA' => 'KOMB', 'MONETA MONEY BANK' => 'MONET', 'PRIMOCO UAV' => 'PRIUA',
        'TATRY MOUNTAIN RESORTS' => 'TMR', 'VIENNA INSURANCE GROUP' => 'VIG', 'VIG' => 'VIG',
        'E4U' => 'EFORU',           'ENERGOAQUA' => 'ENRGA',   'FOOTSHOP' => 'FTSHP',
        'PHILIP MORRIS' => 'TABAK', 'PHOTON ENERGY' => 'PEN',  'RMS MEZZANINE' => 'PVT',
        'SAB FINANCE' => 'SABFG',   'TOMA' => 'TOMA',          'ATOMTRACE' => 'ATOMT',
        'BEZVAVLASY' => 'BEZVA',    'COLOSEUM HOLDING' => 'COLOS', 'EMAN' => 'EMAN',
        'FILLAMENTUM' => 'FILL',    'FIXED.ZONE' => 'FIXED',   'HARDWARIO' => 'HWIO',
        'KARO LEATHER' => 'KARIN',  'M&T 1997' => 'KLIKY',     'M2C HOLDING' => 'M2C',
        'MMCITÉ' => 'MMCTE',        'PILULKA' => 'PINK',       'PRABOS' => 'PRAB',
        'CTP' => 'CTP',             'O2' => 'O2',
    ];

    private const MENY = ['CZK', 'EUR', 'USD', 'PLN', 'HUF', 'GBP'];

    public function getName(): string {
        return 'Fio banka (PDF)';
    }

    public function canParse(string $content, string $filename): bool {
        return str_contains($content, 'Fio banka')
            && (str_contains($content, 'Výpis operací v') || str_contains($content, 'Výpis z účtu'));
    }

    public function parse(string $content): array {
        $t = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $content); // NBSP a úzká mezera
        $t = preg_replace('/[ \t]+/', ' ', $t);
        $t = preg_replace('/\s{2,}/', ' ', $t);

        // Generace se pozná podle názvu sloupce v souhrnu.
        $generace1 = str_contains($t, 'Výnosy z CP') && !str_contains($t, 'Výnosy z IN');

        $out = [];
        foreach ($this->sekce($t, $generace1) as [$mena, $telo]) {
            foreach ($this->radky($telo, $generace1) as $radek) {
                $dto = $generace1 ? $this->radekG1($radek, $mena) : $this->radekG2($radek, $mena);
                if ($dto) $out[] = $dto;
            }
        }
        return $out;
    }

    /**
     * Sekce operací, jedna na měnu; výpis jich má běžně víc (CZK i EUR).
     *
     * V generaci 2 stojí měna hned za nadpisem („Výpis operací v CZK“).
     * V generaci 1 jsou sloupce v PDF obráceně, takže za nadpisem následují
     * nejdřív součty a měna až za nimi — musí se dohledat jako první kód měny
     * v sekci, ne jako pevná pozice.
     *
     * @return array<array{0:string,1:string}>
     */
    private function sekce(string $t, bool $g1): array {
        $meny = implode('|', self::MENY);
        if (!$g1) {
            $casti = preg_split('/Výpis operací v (' . $meny . ')\b/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE);
            $out = [];
            for ($i = 1; $i < count($casti); $i += 2) $out[] = [$casti[$i], $casti[$i + 1] ?? ''];
            return $out;
        }
        $casti = preg_split('/Výpis operací v/u', $t);
        $out = [];
        foreach (array_slice($casti, 1) as $telo) {
            $mena = preg_match('/\b(' . $meny . ')\b/u', $telo, $m) ? $m[1] : 'CZK';
            $out[] = [$mena, $telo];
        }
        return $out;
    }

    /**
     * Rozseká tělo sekce na řádky podle data. Lookbehind na číslici je nutný:
     * bez něj se `16.12.2024` rozpadne na "1" a "6.12.2024" a transakce dostane
     * datum o deset dní jinde.
     */
    private function radky(string $telo, bool $g1): array {
        $vzor = $g1
            ? '/(?<!\d)(?=\d{2}\.\d{2}\.\'\d{2} \d{1,2}:\d{2})/u'
            : '/(?<!\d)(?=\d{1,2}\.\d{1,2}\.\d{4} \d{1,2}:\d{2})/u';
        $kusy = preg_split($vzor, $telo);
        $out = [];
        foreach ($kusy as $k) {
            $k = trim($k);
            if ($k === '' || !preg_match('/^\d{1,2}\./', $k)) continue;
            // Uřízne patičku banky, kdyby se přilepila na poslední řádek.
            $k = preg_split('/Fio banka, a\.s\./u', $k)[0];
            $out[] = trim($k);
        }
        return $out;
    }

    /** Fio píše čísla česky: mezera = tisíce, čárka = desetinná tečka. */
    private function cislo(?string $s): ?float {
        if ($s === null) return null;
        $s = str_replace([' ', "\xc2\xa0"], '', trim($s));
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    /**
     * České číslo. Lookbehind na číslici i písmeno je zásadní: bez něj se
     * koncovka ISINu slepí s následujícím množstvím přes pravidlo o mezerách
     * jako oddělovači tisíců — z `CZ0009093209 350,00` vznikne 209 350 místo 350.
     */
    private const CISLO = '(?<![\d\p{L}])(-?\d{1,3}(?: \d{3})*,\d+|-?\d+,\d+)';

    /** Všechna česká čísla v textu, v pořadí výskytu. */
    private function cisla(string $s): array {
        preg_match_all('/' . self::CISLO . '/u', $s, $m);
        return array_map(fn($x) => $this->cislo($x), $m[1]);
    }

    // ---------------------------------------------------------------- generace 2

    private function radekG2(string $r, string $mena): ?TransactionDTO {
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4}) \d{1,2}:\d{2}\s*(.*)$/su', $r, $m)) return null;
        $datum = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        $zbytek = $m[4];

        $isin = preg_match('/\b([A-Z]{2}[A-Z0-9]{9}\d)\b/', $zbytek, $mi) ? $mi[1] : null;

        // Množství a Objem jsou první dvě čísla za názvem (a případným ISIN).
        $cisla = $this->cisla($zbytek);
        if (count($cisla) < 2) return null;
        $mnozstvi = $cisla[0];
        $objem = $cisla[1];

        // Název je text před prvním číslem, bez ISIN.
        $nazev = preg_split('/' . self::CISLO . '/u', $zbytek)[0];
        if ($isin) $nazev = str_replace($isin, '', $nazev);
        $nazev = trim($nazev);

        // Distribuce/odebrání práv volitelné dividendy a „Ukončení CP v CDCP“ jsou
        // účetní kroky volitelné dividendy — připíšou a zase odepíšou práva
        // (CHO CTP N.V.). Kdyby se importovaly, vznikly by v portfoliu fiktivní
        // pozice; samotná dividenda přijde vlastním řádkem.
        if (preg_match('/Distribuce práv|Odebrání práv|Ukončení CP/ui', $zbytek)) return null;

        // Pozor na pořadí: „Poplatek za připsání dividend“ obsahuje slovo dividend,
        // takže test na poplatek musí být dřív, jinak se z poplatku stane dividenda
        // s tickerem odvozeným z textu poplatku.
        if (preg_match('/\bPoplatek\b/ui', $zbytek)) {
            // U poplatku je částka poslední číslo řádku (Poplatky a náklady),
            // Objem bývá nula.
            $castka = abs((float)end($cisla));
            return $this->dto('FEE', $datum, null, null, 0.0, $castka, $mena, $castka, $zbytek);
        }

        if (preg_match('/\b(Nákup|Prodej)\b/u', $zbytek, $mo, PREG_OFFSET_CAPTURE)) {
            // Řádek, kde je „papírem“ kód měny, je převod mezi měnami účtu.
            if (in_array(strtoupper($nazev), self::MENY, true)) return null;
            $ocas = substr($zbytek, $mo[0][1] + strlen($mo[0][0]));
            $cOcas = $this->cisla($ocas);
            $cena = $cOcas[0] ?? 0.0;
            // Poslední číslo je Podíl PNO (v procentech), poplatek je to před ním.
            $poplatek = count($cOcas) >= 3 ? (float)$cOcas[count($cOcas) - 2] : 0.0;
            return $this->dto(
                strcasecmp($mo[1][0], 'Prodej') === 0 ? 'SELL' : 'BUY',
                $datum, $nazev, $isin, abs((float)$mnozstvi), abs((float)$objem), $mena, abs($poplatek), $zbytek
            );
        }

        // Emisní ážio vyplácí emitent místo dividendy (O2 to dělá pravidelně) —
        // pro držitele je to stejný peněžní výnos.
        if (preg_match('/\bDividend|emisního ážia/ui', $zbytek)) {
            // Dividenda v akciích má množství a nulový objem — to je připsání kusů.
            if ((float)$mnozstvi > 0 && (float)$objem == 0.0) {
                return $this->dto('REVENUE', $datum, $nazev, $isin, abs((float)$mnozstvi), 0.0, $mena, 0.0, $zbytek);
            }
            return $this->dto('DIVIDEND', $datum, $nazev, $isin, 0.0, abs((float)$objem), $mena, 0.0, $zbytek);
        }

        if (preg_match('/Bezhotovostní vklad|Vloženo na účet/ui', $zbytek)) {
            return $this->dto('DEPOSIT', $datum, null, null, 0.0, abs((float)$objem), $mena, 0.0, $zbytek);
        }
        if (preg_match('/Bezhotovostní výběr|Výběr z účtu/ui', $zbytek)) {
            return $this->dto('WITHDRAWAL', $datum, null, null, 0.0, abs((float)$objem), $mena, 0.0, $zbytek);
        }

        // Ukončení CP v CDCP, Finanční kompenzace, Převod… — vyžadují posouzení,
        // radši je nevymýšlet.
        return null;
    }

    // ---------------------------------------------------------------- generace 1

    private function radekG1(string $r, string $mena): ?TransactionDTO {
        // DATUM ČAS | Objem | Poplatky | Cena | Množství | zbytek (Směr Trh Název)
        $vzor = '/^(\d{2})\.(\d{2})\.\'(\d{2}) \d{1,2}:\d{2}\s+'
              . '(-?[\d ]+,\d+)\s+(-?[\d ]+,\d+)\s+(-?[\d ]+,\d+)\s+(-?[\d ]+,\d+)\s*(.*)$/su';
        if (!preg_match($vzor, $r, $m)) return null;

        $datum = sprintf('20%02d-%02d-%02d', $m[3], $m[2], $m[1]);
        $objem    = $this->cislo($m[4]);
        $poplatek = $this->cislo($m[5]);
        $mnozstvi = $this->cislo($m[7]);
        $zbytek   = trim($m[8]);

        if (preg_match('/^(Nákup|Prodej)\s+(\S+)\s+(?:K\s+)?(.*)$/us', $zbytek, $mo)) {
            $nazev = trim($mo[3]);
            if (in_array(strtoupper($nazev), self::MENY, true)) return null; // převod měn
            return $this->dto(
                strcasecmp($mo[1], 'Prodej') === 0 ? 'SELL' : 'BUY',
                $datum, $nazev, null, abs((float)$mnozstvi), abs((float)$objem), $mena, abs((float)$poplatek), $zbytek
            );
        }

        if (preg_match('/Distribuce práv|Odebrání práv|Ukončení CP/ui', $zbytek)) return null;

        // Stejné pořadí jako v generaci 2: poplatek dřív než dividenda, protože
        // „Poplatek za připsání dividend“ vyhoví oběma testům.
        if (preg_match('/\bPoplatek\b/ui', $zbytek)) {
            return $this->dto('FEE', $datum, null, null, 0.0, abs((float)$poplatek), $mena, abs((float)$poplatek), $zbytek);
        }

        if (preg_match('/\bDividend|emisního ážia/ui', $zbytek)) {
            // Název papíru stojí až za závorkou s poznámkou o zdanění.
            $nazev = preg_match('/\)\s*(.+)$/us', $zbytek, $mn) ? trim($mn[1]) : $zbytek;
            return $this->dto('DIVIDEND', $datum, $nazev, null, 0.0, abs((float)$objem), $mena, 0.0, $zbytek);
        }

        if (preg_match('/Bezhotovostní vklad|Vloženo na účet/ui', $zbytek)) {
            return $this->dto('DEPOSIT', $datum, null, null, 0.0, abs((float)$objem), $mena, 0.0, $zbytek);
        }
        if (preg_match('/Bezhotovostní výběr|Výběr z účtu/ui', $zbytek)) {
            return $this->dto('WITHDRAWAL', $datum, null, null, 0.0, abs((float)$objem), $mena, 0.0, $zbytek);
        }

        return null;
    }

    // ---------------------------------------------------------------- pomocné

    /**
     * Ticker podle ISIN, jinak podle názvu. Když se nepodaří, vrátí očištěný
     * název a do metadat se zapíše, že mapování chybí — ať je vidět, co doplnit
     * do tabulky, místo aby transakce tiše zmizela.
     */
    private function ticker(?string $nazev, ?string $isin, array &$meta): ?string {
        if ($isin && isset(self::ISIN_TICKER[$isin])) return self::ISIN_TICKER[$isin];
        if ($nazev) {
            $n = mb_strtoupper(trim($nazev), 'UTF-8');
            foreach (self::NAZEV_TICKER as $klic => $tick) {
                if (str_starts_with($n, $klic)) return $tick;
            }
            $meta['ticker_nenamapovan'] = $nazev;
            if ($isin) $meta['isin'] = $isin;
            $slug = preg_replace('/[^A-Z0-9]/', '', $this->bezDiakritiky($n));
            return $slug !== '' ? substr($slug, 0, 20) : null;
        }
        return null;
    }

    private function bezDiakritiky(string $s): string {
        $z = ['Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N','Ó'=>'O','Ř'=>'R',
              'Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z'];
        return strtr($s, $z);
    }

    private function dto(string $typ, string $datum, ?string $nazev, ?string $isin,
                         float $mnozstvi, float $castka, string $mena, float $poplatek, string $raw): TransactionDTO {
        $meta = ['nazev' => $nazev, 'raw' => mb_substr(trim($raw), 0, 180)];
        if ($isin) $meta['isin'] = $isin;

        $t = new TransactionDTO();
        $t->type = $typ;
        $t->date = $datum;
        $t->ticker = in_array($typ, ['DEPOSIT', 'WITHDRAWAL'], true) ? null : $this->ticker($nazev, $isin, $meta);
        $t->quantity = $mnozstvi;
        $t->pricePerUnit = $mnozstvi > 0 ? abs($castka / $mnozstvi) : 0.0;
        $t->currency = $mena;
        $t->fee = $poplatek;
        $t->totalAmount = $castka;
        $t->source_broker = 'Fio';
        $t->metadata = $meta;
        // Do otisku patří i samotný řádek: generace 2 v něm nese „ID operace“,
        // takže dva jinak shodné zápisy (dvě stejné dividendy téhož dne) zůstanou
        // rozlišitelné a druhý se nezahodí jako duplicita.
        $t->brokerTradeId = 'FIO_' . md5(implode('|', [
            $datum, (string)$t->ticker, $typ, $mnozstvi, $castka, $mena, $poplatek,
            preg_replace('/\s+/u', ' ', trim($raw)),
        ]));
        return $t;
    }
}
