<?php
namespace Broker\V3\Import\Xlsx;

use Broker\V3\Import\AbstractParser;
use Broker\V3\Import\TransactionDTO;

/**
 * eToro — Account Statement (XLSX).
 *
 * XLSX je ZIP s XML uvnitř, proto potřebuje rozšíření `zip` (přidané do
 * Dockerfile jako `php83-zip`). PDF varianta téhož výpisu existuje, ale sešit
 * je strukturovaný, takže se z něj čte spolehlivěji.
 *
 * Sešit má sedm listů. Zpracovává se **jen `Account Activity`** — to je
 * transakční log. `Dividends` obsahuje tytéž dividendy znovu (jen s rozpadem
 * srážkové daně), takže by se při čtení obou započítaly dvakrát. `Holdings`
 * není seznam transakcí, ale **historie snapshotů** (86 řádků = 12 snapshotů
 * po 10 pozicích), takže se z něj pozice odvozovat nedá.
 *
 * Sloupce: Date | Type | Details | Amount | Units / Contracts | Realized Equity
 * Change | Realized Equity | Balance | Position ID | Asset type | NWA
 * `Details` má tvar `MSFT/USD`, tedy ticker a měna obchodu.
 *
 * **Split musí dopočítat parser.** Řádek `corp action: Split` má `Details`
 * „XLK/USD 2:1“, ale `Amount` 0 a `Units` prázdné — počet kusů v něm není.
 * Ověřeno na snapshotech: pozice XLK šla 0,876795 → 1,75359 přesně u splitu
 * a otevírací kurz se zároveň půlil, takže pořizovací cena zůstala stejná.
 * Parser proto sleduje počet kusů podle `Position ID` a při splitu doplní
 * rozdíl jako připsání s nulovou cenou — pozice sedí a cena se nezmění.
 */
class EtoroXlsxParser extends AbstractParser {

    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function getName(): string {
        return 'eToro Account Statement (XLSX)';
    }

    public function canParse(string $content, string $filename): bool {
        if (stripos($filename, 'etoro') === false && substr($content, 0, 2) !== 'PK') return false;
        $listy = $this->listy($content);
        return $listy !== null && isset($listy['Account Activity']);
    }

    public function parse(string $content): array {
        $listy = $this->listy($content);
        if ($listy === null) {
            throw new \Exception('eToro XLSX: nelze otevřít sešit (chybí rozšíření zip?).');
        }
        $radky = $listy['Account Activity'] ?? [];
        if (count($radky) < 2) return [];

        $hlavicka = array_map(fn($h) => trim((string)$h), array_shift($radky));
        $out = [];
        $kusy = [];   // Position ID => běžící počet kusů, kvůli splitům

        foreach ($radky as $r) {
            $d = $this->sloupce($hlavicka, $r);
            $dto = $this->radek($d, $kusy);
            if ($dto) $out[] = $dto;
        }
        return $out;
    }

    private function sloupce(array $hlavicka, array $hodnoty): array {
        $out = [];
        foreach ($hlavicka as $i => $n) {
            $v = trim((string)($hodnoty[$i] ?? ''));
            $out[$n] = ($v === '-') ? '' : $v;
        }
        return $out;
    }

    private function radek(array $d, array &$kusy): ?TransactionDTO {
        $datum = $this->datum($d['Date'] ?? '');
        if (!$datum) return null;

        $druh = trim($d['Type'] ?? '');
        $castka = abs((float)$this->cislo($d['Amount'] ?? ''));
        $jednotky = abs((float)$this->cislo($d['Units / Contracts'] ?? ''));
        $pozice = trim($d['Position ID'] ?? '');
        [$ticker, $mena] = $this->tickerAMena($d['Details'] ?? '');

        switch (true) {
            // "Open Position" i "Open position - Spinoff" (akcie z odštěpení).
            case stripos($druh, 'Open position') === 0:
                if ($ticker === null || $jednotky <= 0) return null;
                if ($pozice !== '') $kusy[$pozice] = ($kusy[$pozice] ?? 0) + $jednotky;
                return $this->dto('BUY', $datum, $ticker, $jednotky, $castka, $mena, $d);

            case stripos($druh, 'Position closed') === 0:
                if ($ticker === null) return null;
                if ($pozice !== '') $kusy[$pozice] = max(0, ($kusy[$pozice] ?? 0) - $jednotky);
                return $this->dto('SELL', $datum, $ticker, $jednotky, $castka, $mena, $d);

            case stripos($druh, 'corp action') !== false:
                return $this->split($d, $datum, $ticker, $mena, $pozice, $kusy);

            case strcasecmp($druh, 'Dividend') === 0:
                if ($ticker === null) return null;
                return $this->dto('DIVIDEND', $datum, $ticker, 0.0, $castka, $mena, $d);

            case stripos($druh, 'fee') !== false:      // Overnight fee, ...
                return $this->dto('FEE', $datum, null, 0.0, $castka, $mena, $d, $castka);

            case strcasecmp($druh, 'Deposit') === 0:
                return $this->dto('DEPOSIT', $datum, null, 0.0, $castka, $mena, $d);

            case stripos($druh, 'Withdraw') !== false:
                return $this->dto('WITHDRAWAL', $datum, null, 0.0, $castka, $mena, $d);

            default:
                return null;
        }
    }

    /**
     * Split. Poměr je až v textu `Details` („XLK/USD 2:1“) a počet kusů řádek
     * neuvádí, takže se dopočítá z běžícího stavu pozice.
     */
    private function split(array $d, string $datum, ?string $ticker, string $mena,
                           string $pozice, array &$kusy): ?TransactionDTO {
        if ($ticker === null || $pozice === '') return null;
        if (!preg_match('~(\d+(?:[.,]\d+)?)\s*:\s*(\d+(?:[.,]\d+)?)~', $d['Details'] ?? '', $m)) return null;

        $novych = (float)str_replace(',', '.', $m[1]);
        $starych = (float)str_replace(',', '.', $m[2]);
        $drzeno = $kusy[$pozice] ?? 0.0;
        if ($novych <= 0 || $starych <= 0 || $drzeno <= 0) return null;

        $poSplitu = $drzeno * ($novych / $starych);
        $rozdil = $poSplitu - $drzeno;
        $kusy[$pozice] = $poSplitu;
        if (abs($rozdil) < 1e-9) return null;

        // Připsané kusy nic nestály — pořizovací cena pozice zůstává beze změny.
        return $this->dto('REVENUE', $datum, $ticker, abs($rozdil), 0.0, $mena, $d);
    }

    /** `MSFT/USD` → ['MSFT','USD']; `T.US/USD` → ['T','USD']. */
    private function tickerAMena(string $detaily): array {
        if (!preg_match('~^\s*([A-Za-z0-9._-]+)\s*/\s*([A-Z]{3})~', trim($detaily), $m)) {
            return [null, 'USD'];
        }
        $t = strtoupper($m[1]);
        // eToro připojuje k americkým titulům `.US` (T.US = AT&T).
        $t = preg_replace('/\.US$/', '', $t);
        return [$t, strtoupper($m[2])];
    }

    /** `12/05/2021 16:58:22` → `2021-05-12`. */
    private function datum(string $s): ?string {
        if (preg_match('~(\d{1,2})/(\d{1,2})/(\d{4})~', trim($s), $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('~(\d{4})-(\d{2})-(\d{2})~', trim($s), $m)) return "$m[1]-$m[2]-$m[3]";
        return null;
    }

    private function cislo(?string $s): ?float {
        if ($s === null) return null;
        $v = preg_replace('/[^0-9.\-]/u', '', str_replace(',', '', $s));
        return ($v === '' || $v === '-') ? null : (float)$v;
    }

    private function dto(string $typ, string $datum, ?string $ticker, float $jednotky,
                         float $castka, string $mena, array $d, float $poplatek = 0.0): TransactionDTO {
        $t = new TransactionDTO();
        $t->type = $typ;
        $t->date = $datum;
        $t->ticker = $ticker;
        $t->quantity = $jednotky;
        $t->pricePerUnit = $jednotky > 0 ? abs($castka / $jednotky) : 0.0;
        $t->currency = $mena;
        $t->fee = $poplatek;
        $t->totalAmount = $castka;
        $t->source_broker = 'eToro';
        $t->metadata = [
            'etoro_typ' => $d['Type'] ?? '',
            'detaily' => mb_substr((string)($d['Details'] ?? ''), 0, 80),
            'position_id' => $d['Position ID'] ?? '',
            'asset_type' => $d['Asset type'] ?? '',
        ];
        $t->brokerTradeId = 'ETORO_' . md5(implode('|', [
            $datum, (string)$ticker, $typ, $jednotky, $castka, $mena,
            $d['Position ID'] ?? '', $d['Type'] ?? '', $d['Date'] ?? '',
        ]));
        return $t;
    }

    // ------------------------------------------------------------ čtení XLSX

    /**
     * Načte sešit: název listu => pole řádků (pole buněk).
     * Vrací null, když se archiv nepodaří otevřít.
     */
    private function listy(string $content): ?array {
        if (!class_exists('\ZipArchive')) return null;

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) return null;
        file_put_contents($tmp, $content);

        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); return null; }

        try {
            $sdilene = $this->sdileneRetezce($zip);

            $wb = $zip->getFromName('xl/workbook.xml') ?: '';
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels') ?: '';

            // V relacích nejsou atributy v pevném pořadí a cesta bývá absolutní.
            $cesty = [];
            if (preg_match_all('~<Relationship\b[^>]*/>~', $rels, $mm)) {
                foreach ($mm[0] as $el) {
                    if (preg_match('~Id="([^"]+)"~', $el, $a) && preg_match('~Target="([^"]+)"~', $el, $b)) {
                        $cesty[$a[1]] = ltrim($b[1], '/');
                    }
                }
            }

            $out = [];
            // Prefix jmenného prostoru se liší podle exportéru (`x:sheet` i `sheet`).
            if (preg_match_all('~<(?:\w+:)?sheet\b[^>]*/>~', $wb, $ms)) {
                foreach ($ms[0] as $el) {
                    if (!preg_match('~name="([^"]+)"~', $el, $n)) continue;
                    if (!preg_match('~r:id="([^"]+)"~', $el, $r)) continue;
                    $cesta = $cesty[$r[1]] ?? null;
                    if ($cesta === null) continue;
                    $xml = $zip->getFromName($cesta);
                    if ($xml === false) continue;
                    $out[$n[1]] = $this->radkyListu($xml, $sdilene);
                }
            }
            return $out;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    private function sdileneRetezce(\ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return [];
        $sx->registerXPathNamespace('m', self::NS);
        $out = [];
        foreach ($sx->xpath('//m:si') ?: [] as $si) {
            // Text bývá rozdělený do víc <t> (formátované úseky) — spojit.
            $si->registerXPathNamespace('m', self::NS);
            $out[] = implode('', array_map('strval', $si->xpath('.//m:t') ?: []));
        }
        return $out;
    }

    private function radkyListu(string $xml, array $sdilene): array {
        $sx = @simplexml_load_string($xml);
        if ($sx === false) return [];
        $sx->registerXPathNamespace('m', self::NS);

        $out = [];
        foreach ($sx->xpath('//m:row') ?: [] as $row) {
            $row->registerXPathNamespace('m', self::NS);
            $radek = [];
            foreach ($row->xpath('m:c') ?: [] as $c) {
                $c->registerXPathNamespace('m', self::NS);
                $typ = (string)($c['t'] ?? '');
                if ($typ === 'inlineStr') {
                    $radek[] = implode('', array_map('strval', $c->xpath('.//m:t') ?: []));
                    continue;
                }
                $v = $c->xpath('m:v');
                $hodnota = $v ? (string)$v[0] : '';
                if ($typ === 's' && $hodnota !== '' && ctype_digit($hodnota)) {
                    $hodnota = $sdilene[(int)$hodnota] ?? $hodnota;
                }
                $radek[] = $hodnota;
            }
            $out[] = $radek;
        }
        return $out;
    }
}
