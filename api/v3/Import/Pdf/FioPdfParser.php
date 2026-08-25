<?php
namespace Broker\V3\Import\Pdf;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * Fio banka — výpis z obchodního účtu (PDF).
 *
 * Vstupem je výstup `pdftotext -layout` (tak si obsah tahá ImportManager), který
 * zachovává sloupce a **odděluje je dvěma a více mezerami**. Na tom celý parser
 * stojí; kdyby se text extrahoval jinak (bez -layout), pořadí sloupců se rozpadne.
 *
 * Fio formát dvakrát změnilo. Generace se pozná podle názvu sloupce v souhrnu,
 * který se měnil spolu se strukturou:
 *
 *   GENERACE 1 — „Výnosy z CP“ (2020 až začátek 2021). Jedna transakce = jeden
 *   řádek, na konci vždy čtyři čísla:
 *     Datum a čas | Název | Směr | Trh | Poznámka | Množství | Cena | Poplatky | Objem
 *     13.08.'20 14:51   ČEZ, A.S.   K   BCPP   Nákup   15,00   476,00   50,00   -7 140,00
 *
 *   GENERACE 2 — „Výnosy z IN“ (od jara 2021). Jedna transakce = **blok několika
 *   řádků**; každý řádek nese jednu hodnotu zarovnanou vpravo:
 *     2.1.2025 10:26   ČEZ, A.S.   CZ0005112300   21,00   -20 181,00
 *     BCPP             Nákup                         961,00      <- jednotková cena
 *     47561440457                                     80,63      <- poplatky
 *                                                      0,39 %    <- podíl PNO
 *
 * Pozdější výpisy generace 2 mají na titulní straně navíc „Spisová značka“, ale
 * tabulka operací je totožná (ověřeno na 2024-01, které existuje ve dvou
 * staženích — před tou změnou a po ní).
 *
 * Měna se bere z nadpisu `Výpis operací v <MĚNA>`; výpis má běžně sekci CZK i EUR.
 */
class FioPdfParser extends AbstractParser {

    /** ISIN → ticker; převzato z api/js/data/TickerMap.js. */
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

    /** Název → ticker. Porovnává se na začátek názvu, Fio je píše nejednotně. */
    private const NAZEV_TICKER = [
        'COLT CZ GROUP' => 'CZG', 'ČEZ' => 'CEZ', 'DOOSAN ŠKODA POWER' => 'DSPW',
        'ERSTE GROUP BANK' => 'ERBAG', 'GEVORKYAN' => 'GEV', 'KOFOLA' => 'KOFOL',
        'KOMERČNÍ BANKA' => 'KOMB', 'MONETA MONEY BANK' => 'MONET', 'PRIMOCO UAV' => 'PRIUA',
        'TATRY MOUNTAIN RESORTS' => 'TMR', 'VIENNA INSURANCE GROUP' => 'VIG', 'VIG' => 'VIG',
        'E4U' => 'EFORU', 'ENERGOAQUA' => 'ENRGA', 'FOOTSHOP' => 'FTSHP',
        'PHILIP MORRIS' => 'TABAK', 'PHOTON ENERGY' => 'PEN', 'RMS MEZZANINE' => 'PVT',
        'SAB FINANCE' => 'SABFG', 'TOMA' => 'TOMA', 'ATOMTRACE' => 'ATOMT',
        'BEZVAVLASY' => 'BEZVA', 'COLOSEUM HOLDING' => 'COLOS', 'EMAN' => 'EMAN',
        'FILLAMENTUM' => 'FILL', 'FIXED.ZONE' => 'FIXED', 'HARDWARIO' => 'HWIO',
        'KARO LEATHER' => 'KARIN', 'M&T 1997' => 'KLIKY', 'M2C HOLDING' => 'M2C',
        'MMCITÉ' => 'MMCTE', 'PILULKA' => 'PINK', 'PRABOS' => 'PRAB',
        'CTP' => 'CTP', 'O2' => 'O2',
    ];

    private const MENY = ['CZK', 'EUR', 'USD', 'PLN', 'HUF', 'GBP'];

    /** Účetní kroky volitelné dividendy — práva se připíšou a zase odepíšou. */
    private const KORPORATNI = '/Distribuce práv|Odebrání práv|Ukončení CP/ui';

    public function getName(): string {
        return 'Fio banka (PDF)';
    }

    public function canParse(string $content, string $filename): bool {
        return str_contains($content, 'Fio banka')
            && (str_contains($content, 'Výpis operací v') || str_contains($content, 'Výnosy z'));
    }

    public function parse(string $content): array {
        $text = str_replace(["\xc2\xa0", "\xe2\x80\xaf", "\r", "\f"], [' ', ' ', '', "\n"], $content);
        $g1 = str_contains($text, 'Výnosy z CP') && !str_contains($text, 'Výnosy z IN');

        $out = [];
        foreach ($this->sekce($text) as [$mena, $telo]) {
            $bloky = $this->bloky($telo, $g1);
            foreach ($bloky as $blok) {
                $dto = $g1 ? $this->blokG1($blok, $mena) : $this->blokG2($blok, $mena);
                if ($dto) $out[] = $dto;
            }
        }

        /*
         * Fio uvádí některé řádky dvakrát (protistrany převodu mezi vlastními
         * účty). Bez rozlišení mají shodný otisk a druhý by se zahodil jako
         * duplicita. Pořadí v rámci shodných řádků je stabilní i mezi
         * překrývajícími se výpisy, takže deduplikace dál funguje; první řádek
         * si nechává původní otisk, aby už naimportovaná data zůstala platná.
         */
        $pocty = [];
        foreach ($out as $dto) {
            $zaklad = $dto->brokerTradeId;
            $pocty[$zaklad] = ($pocty[$zaklad] ?? 0) + 1;
            if ($pocty[$zaklad] > 1) $dto->brokerTradeId = $zaklad . '_' . $pocty[$zaklad];
        }
        return $out;
    }

    /**
     * Sekce operací, jedna na měnu. Nadpis „Výpis operací v CZK“ může být
     * v generaci 1 zalomený, proto se měna hledá i na následujícím řádku.
     *
     * @return array<array{0:string,1:string}>
     */
    private function sekce(string $text): array {
        $meny = implode('|', self::MENY);
        $casti = preg_split('/Výpis operací v\s*/u', $text);
        $out = [];
        foreach (array_slice($casti, 1) as $telo) {
            $mena = preg_match('/^\s*(' . $meny . ')\b/u', $telo, $m) ? $m[1]
                  : (preg_match('/\b(' . $meny . ')\b/u', $telo, $m2) ? $m2[1] : 'CZK');
            $out[] = [$mena, $telo];
        }
        return $out;
    }

    /**
     * Rozseká sekci na bloky. Blok začíná řádkem, který má vlevo datum, a končí
     * před dalším takovým řádkem — v generaci 2 patří k jedné transakci několik
     * řádků, v generaci 1 se ještě zalamují dlouhé popisy.
     *
     * @return array<string[]>  bloky jako pole řádků
     */
    private function bloky(string $telo, bool $g1): array {
        $vzorDatum = $g1
            ? '/^\s*\d{2}\.\d{2}\.\'\d{2}\s+\d{1,2}:\d{2}\b/u'
            : '/^\s*\d{1,2}\.\d{1,2}\.\d{4}\s+\d{1,2}:\d{2}\b/u';

        $bloky = [];
        $akt = null;
        foreach (explode("\n", $telo) as $radek) {
            if (trim($radek) === '') continue;
            // Patička a hlavičky stránek do transakcí nepatří.
            if (preg_match('/Fio banka, a\.s\.|^\s*Číslo (účtu|výpisu|o\.ú\.)|Strana\s|^\s*\d+\s*[\/z]\s*\d+\s*$/u', $radek)) continue;
            if (preg_match('/^\s*Datum a čas\b|Podíl PNO\s*$|^\s*(Trh|ID operace)\s*$/u', $radek)) continue;

            if (preg_match($vzorDatum, $radek)) {
                if ($akt !== null) $bloky[] = $akt;
                $akt = [$radek];
            } elseif ($akt !== null) {
                $akt[] = $radek;
            }
        }
        if ($akt !== null) $bloky[] = $akt;
        return $bloky;
    }

    /** Sloupce jsou v -layout odděleny dvěma a více mezerami. */
    private function sloupce(string $radek): array {
        return array_values(array_filter(preg_split('/\s{2,}/u', trim($radek)), fn($x) => $x !== ''));
    }

    /** Fio píše čísla česky: mezera = tisíce, čárka = desetinná tečka. */
    private function cislo(?string $s): ?float {
        if ($s === null) return null;
        $s = str_replace([' ', '%'], '', trim($s));
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    private function jeCislo(string $s): bool {
        return (bool)preg_match('/^-?\d[\d ]*,\d+\s*%?$/u', trim($s));
    }

    // ---------------------------------------------------------------- generace 1

    /**
     * Jeden řádek, na konci čtyři čísla: Množství, Cena, Poplatky, Objem.
     * Text mezi datem a prvním číslem drží Název, Směr, Trh a Poznámku.
     */
    private function blokG1(array $blok, string $mena): ?TransactionDTO {
        $sl = $this->sloupce($blok[0]);
        if (count($sl) < 5) return null;
        if (!preg_match('/^(\d{2})\.(\d{2})\.\'(\d{2})/u', $sl[0], $md)) return null;
        $datum = sprintf('20%02d-%02d-%02d', $md[3], $md[2], $md[1]);

        // Čtyři koncová čísla.
        $cisla = [];
        while (count($sl) > 1 && count($cisla) < 4 && $this->jeCislo(end($sl))) {
            array_unshift($cisla, $this->cislo(array_pop($sl)));
        }
        if (count($cisla) < 4) return null;
        [$mnozstvi, $cena, $poplatek, $objem] = $cisla;

        // Zbytek = popisné sloupce; zalomené pokračování je na dalších řádcích.
        $popisne = array_slice($sl, 1);
        $pokracovani = [];
        foreach (array_slice($blok, 1) as $r) $pokracovani = array_merge($pokracovani, $this->sloupce($r));
        $popis = trim(implode(' ', array_merge($popisne, $pokracovani)));

        $nazev = $popisne[0] ?? '';
        // Když je sloupec Název prázdný, první sloupec už je Směr nebo Poznámka.
        // Kódy směru se musí porovnávat na CELÝ token: „KOMERČNÍ BANKA“ začíná na
        // K a „PHILIP MORRIS“ na P, takže test na prefix by ty názvy zahodil.
        if ($nazev === ''
            || preg_match('/^(K|P|BCPP|RMS|VOLNY)$/u', $nazev)
            || preg_match('/^(Nákup|Prodej|Poplatek|Převod|Vloženo|Výběr|BAA)/u', $nazev)) {
            $nazev = '';
        }

        return $this->zPopisu($datum, $popis, $nazev, null, $mnozstvi, $cena, $poplatek, $objem, $mena);
    }

    // ---------------------------------------------------------------- generace 2

    /**
     * Blok řádků. První nese datum, název, ISIN, množství a objem; další pak
     * vždy jednu hodnotu zarovnanou vpravo — jednotkovou cenu, poplatky, podíl.
     */
    private function blokG2(array $blok, string $mena): ?TransactionDTO {
        $prvni = $this->sloupce($blok[0]);
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $prvni[0] ?? '', $md)) return null;
        $datum = sprintf('%04d-%02d-%02d', $md[3], $md[2], $md[1]);

        // Množství a Objem jsou dvě koncová čísla prvního řádku.
        $ocas = [];
        while (count($prvni) > 1 && count($ocas) < 2 && $this->jeCislo(end($prvni))) {
            array_unshift($ocas, $this->cislo(array_pop($prvni)));
        }
        if (count($ocas) < 2) return null;
        [$mnozstvi, $objem] = $ocas;

        $isin = preg_match('/\b([A-Z]{2}[A-Z0-9]{9}\d)\b/', implode(' ', $prvni), $mi) ? $mi[1] : null;
        $nazev = $prvni[1] ?? '';
        if ($isin !== null && $nazev === $isin) $nazev = '';

        // Zbylé řádky: číslo vpravo je hodnota, text vlevo její význam.
        $cena = 0.0; $poplatek = 0.0; $popisDalsi = [];
        $poradiCisel = [];
        foreach (array_slice($blok, 1) as $r) {
            $c = $this->sloupce($r);
            if ($c === []) continue;
            $hodnota = $this->jeCislo(end($c)) ? $this->cislo(end($c)) : null;
            $text = $hodnota === null ? implode(' ', $c) : implode(' ', array_slice($c, 0, -1));
            if (trim($text) !== '') $popisDalsi[] = trim($text);
            if ($hodnota !== null && !str_contains(end($c), '%')) $poradiCisel[] = $hodnota;
        }
        // Pořadí je dané rozvržením: jednotková cena, pak poplatky.
        $cena = $poradiCisel[0] ?? 0.0;
        $poplatek = $poradiCisel[1] ?? 0.0;

        $popis = trim($nazev . ' ' . implode(' ', $popisDalsi));
        return $this->zPopisu($datum, $popis, $nazev, $isin, $mnozstvi, $cena, $poplatek, $objem, $mena);
    }

    // ---------------------------------------------------------------- společné

    /** Z popisu určí typ operace a poskládá transakci. */
    private function zPopisu(string $datum, string $popis, string $nazev, ?string $isin,
                             ?float $mnozstvi, ?float $cena, ?float $poplatek, ?float $objem,
                             string $mena): ?TransactionDTO {
        $mnozstvi = abs((float)$mnozstvi);
        $objem = abs((float)$objem);
        $poplatek = abs((float)$poplatek);

        if (preg_match(self::KORPORATNI, $popis)) return null;

        // Poplatek musí předcházet dividendu: „Poplatek za připsání dividend“
        // vyhoví oběma testům a jinak by se z poplatku stala dividenda.
        if (preg_match('/\bPoplatek\b/ui', $popis)) {
            $castka = $poplatek ?: $objem;
            return $this->dto('FEE', $datum, null, null, 0.0, $castka, $mena, $castka, $popis);
        }

        if (preg_match('/Bezhotovostní vklad|Vloženo na účet/ui', $popis)) {
            return $this->dto('DEPOSIT', $datum, null, null, 0.0, $objem, $mena, 0.0, $popis);
        }
        if (preg_match('/Bezhotovostní výběr|Výběr z účtu/ui', $popis)) {
            return $this->dto('WITHDRAWAL', $datum, null, null, 0.0, $objem, $mena, 0.0, $popis);
        }

        /*
         * Převody. Pozici nemění a obchod to není, ale peníze z účtu skutečně
         * odcházejí a přicházejí — „Převod na účet 123-3696340217/0100“ jsou
         * dva výběry za 223 500 a 85 700 Kč, které se dřív zahazovaly úplně.
         * Bez nich nedává zůstatek na účtu smysl.
         *
         * Nulové převody jsou protistrana směny měn (ta se řeší jinde), ty
         * se přeskočí, aby nevznikaly prázdné řádky.
         */
        // Pozor na tvar slova: Fio píše „Převod z účtu“, ale „Převod na účet“ —
        // proto se porovnává jen kmen „úč“, jinak jeden z tvarů propadne.
        if (preg_match('/Převod\s+(z|na)\s+úč/ui', $popis, $ms)) {
            if ($objem == 0.0) return null;
            return $this->dto(
                strcasecmp($ms[1], 'na') === 0 ? 'WITHDRAWAL' : 'DEPOSIT',
                $datum, null, null, 0.0, $objem, $mena, 0.0, $popis
            );
        }

        // Emisní ážio vyplácí emitent místo dividendy (O2) — pro držitele stejný výnos.
        if (preg_match('/\bDividend|emisního ážia/ui', $popis)) {
            if ($mnozstvi > 0 && $objem == 0.0) {
                /*
                 * Dividenda v akciích (CTP vyplácí volitelnou dividendu): připsané
                 * kusy, žádná hotovost. Výpis u nich žádnou částku neuvádí, takže
                 * by v portfoliu ležely s nulovou pořizovací cenou a v dividendách
                 * by nebyly vůbec. Ocení je `v3/cost_basis.php` kurzem toho dne;
                 * příznak je tu proto, ať se to nemusí hádat z textu popisu.
                 */
                return $this->dto('REVENUE', $datum, $nazev, $isin, $mnozstvi, 0.0, $mena, 0.0, $popis,
                    ['dividenda_v_akciich' => true, 'basis_status' => 'UNKNOWN']);
            }
            return $this->dto('DIVIDEND', $datum, $nazev, $isin, 0.0, $objem, $mena, 0.0, $popis);
        }

        if (preg_match('/\b(Nákup|Prodej)\b/u', $popis, $mo)) {
            // Řádek, kde je „papírem“ kód měny, je převod mezi měnami účtu.
            /*
             * Řádek, kde je „papírem“ kód měny, je směna mezi měnami účtu.
             * Pozici nemění a jako vklad/výběr se neeviduje — ve výpisu transakcí
             * by to lhalo. (Zůstatek hotovosti se proto z toků odvodit nedá,
             * viz poznámka u třídy.)
             */
            if (in_array(strtoupper(trim($nazev)), self::MENY, true)) return null;
            if ($mnozstvi == 0.0) return null;
            $castka = $objem ?: ($mnozstvi * (float)$cena);
            return $this->dto(
                strcasecmp($mo[1], 'Prodej') === 0 ? 'SELL' : 'BUY',
                $datum, $nazev, $isin, $mnozstvi, $castka, $mena, $poplatek, $popis
            );
        }

        return null;
    }

    /**
     * Ticker podle ISIN, jinak podle názvu. Když se nepodaří, vrátí očištěný
     * název a do metadat zapíše, že mapování chybí — ať je vidět, co doplnit.
     */
    private function ticker(?string $nazev, ?string $isin, array &$meta): ?string {
        if ($isin && isset(self::ISIN_TICKER[$isin])) return self::ISIN_TICKER[$isin];
        $n = mb_strtoupper(trim((string)$nazev), 'UTF-8');
        if ($n === '') return null;
        foreach (self::NAZEV_TICKER as $klic => $tick) {
            if (str_starts_with($n, $klic)) return $tick;
        }
        $meta['ticker_nenamapovan'] = $nazev;
        if ($isin) $meta['isin'] = $isin;
        $slug = preg_replace('/[^A-Z0-9]/', '', $this->bezDiakritiky($n));
        return $slug !== '' ? substr($slug, 0, 20) : null;
    }

    private function bezDiakritiky(string $s): string {
        return strtr($s, ['Á'=>'A','Č'=>'C','Ď'=>'D','É'=>'E','Ě'=>'E','Í'=>'I','Ň'=>'N',
                          'Ó'=>'O','Ř'=>'R','Š'=>'S','Ť'=>'T','Ú'=>'U','Ů'=>'U','Ý'=>'Y','Ž'=>'Z']);
    }

    private function dto(string $typ, string $datum, ?string $nazev, ?string $isin,
                         float $mnozstvi, float $castka, string $mena, float $poplatek,
                         string $popis, array $navic = []): TransactionDTO {
        $meta = array_merge(['nazev' => $nazev, 'popis' => mb_substr(trim($popis), 0, 180)], $navic);
        if ($isin) $meta['isin'] = $isin;

        $t = new TransactionDTO();
        $t->type = $typ;
        $t->date = $datum;
        // Hotovostní pohyby nemají papír; ticker doplní import podle měny,
        // sloupec `ticker` je v databázi NOT NULL.
        $t->ticker = in_array($typ, ['DEPOSIT', 'WITHDRAWAL', 'FEE'], true)
            ? null : $this->ticker($nazev, $isin, $meta);
        $t->quantity = $mnozstvi;
        $t->pricePerUnit = $mnozstvi > 0 ? abs($castka / $mnozstvi) : 0.0;
        $t->currency = $mena;
        $t->fee = $poplatek;
        $t->totalAmount = $castka;
        $t->source_broker = 'Fio';
        $t->metadata = $meta;
        /*
         * Otisk staví na „ID operace“, které Fio u každé operace uvádí (generace 2).
         * Celý popis se do otisku dávat nesmí: tentýž obchod se v ročním výpisu
         * jmenuje „COLTCZ“ a ve čtvrtletním „COLT CZ GROUP SE“, takže by se
         * z jednoho nákupu staly dva. Generace 1 ID nemá, tam zůstává popis.
         */
        $idOperace = preg_match('/(?<!\d)(\d{9,})(?!\d)/', $popis, $mid) ? $mid[1] : null;
        $t->brokerTradeId = 'FIO_' . md5(implode('|', $idOperace !== null
            ? [$datum, $typ, $idOperace]
            : [$datum, (string)$t->ticker, $typ, $mnozstvi, $castka, $mena, $poplatek,
               preg_replace('/\s+/u', ' ', trim($popis))]));
        return $t;
    }
}
