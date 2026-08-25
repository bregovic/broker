<?php
/**
 * Dopočet pořizovací ceny u pozic, které k nám přišly převodem.
 *
 * Když kryptoměnu jen přesuneme z jiného vlastního účtu (Coinbase Pro, GDAX),
 * výpis u převodu uvádí tržní hodnotu toho dne — ne to, co jsme za ni zaplatili.
 * Parser proto takový řádek uloží s nulovou cenou a `basis_status = UNKNOWN`.
 *
 * Tenhle modul se ji pokusí odvodit z peněz: sečte, kolik fiatu na burzu odešlo
 * (`INTERNAL`, směr `na_burzu`) a kolik se ho vrátilo, a rozdíl rozpustí mezi
 * převedené pozice v poměru jejich tržní hodnoty v den převodu.
 *
 * Je to odvození, ne fakt — u Coinbase to ale sedí přesně: v roce 2022 odešlo
 * na GDAX 1 002 408 Kč, zpátky nepřišla ani koruna a vrátilo se 1,22992676 BTC.
 * Pořizovací cena tedy vychází 815 014 Kč/BTC, zatímco tržní ocenění převodu
 * (30. 11. 2022, blízko dna cyklu) dávalo 394 878 Kč/BTC.
 *
 * Řádky, u kterých odvození nevyjde, zůstanou `UNKNOWN` — aplikace u nich zisk
 * radši nevyčíslí, než aby ukázala číslo, které vzniklo z tržní ceny převodu.
 */

if (!function_exists('dopocitat_porizovaci_ceny')) {

    /**
     * Projde platformy uživatele a doplní pořizovací cenu u převedených pozic.
     *
     * @return array<string,array{prevodu:int,odvozeno:int,vlozeno_czk:float}> podle platformy
     */
    function dopocitat_porizovaci_ceny(PDO $pdo, int $userId, ?string $platforma = null): array {
        $kde = "user_id = ?";
        $args = [$userId];
        if ($platforma !== null) { $kde .= " AND platform = ?"; $args[] = $platforma; }

        $stmt = $pdo->prepare(
            "SELECT trans_id, date, ticker, trans_type, amount, amount_cur, amount_czk,
                    ex_rate, currency, platform, product_type, metadata
             FROM transactions WHERE $kde ORDER BY platform, date, trans_id"
        );
        $stmt->execute($args);

        $podlePlatformy = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $podlePlatformy[$r['platform'] ?? ''][] = $r;
        }

        $vysledek = [];
        foreach ($podlePlatformy as $nazev => $radky) {
            $shrnuti = zpracovat_platformu($pdo, $radky);
            if ($shrnuti['prevodu'] > 0) $vysledek[$nazev] = $shrnuti;
        }
        return $vysledek;
    }

    /** @param array<int,array<string,mixed>> $radky */
    function zpracovat_platformu(PDO $pdo, array $radky): array {
        $naBurzu = 0.0;   // fiat poslaný na interní burzu
        $zBurzy  = 0.0;   // fiat, který se z ní vrátil
        $prevody = [];    // řádky čekající na pořizovací cenu

        foreach ($radky as $r) {
            $meta = dekodovat_meta($r['metadata']);

            if (strcasecmp((string)$r['trans_type'], 'INTERNAL') === 0) {
                $castka = abs((float)$r['amount_czk']);
                if (($meta['smer'] ?? '') === 'z_burzy') $zBurzy += $castka;
                else $naBurzu += $castka;
                continue;
            }

            // Zajímají nás jen příchozí převody pozice, u kterých cenu neznáme.
            if (empty($meta['prevod'])) continue;
            if (strcasecmp((string)$r['trans_type'], 'BUY') !== 0) continue;
            if (($meta['basis_status'] ?? '') === 'RUCNI') continue;   // ruční zadání má přednost
            $prevody[] = ['radek' => $r, 'meta' => $meta];
        }

        $shrnuti = ['prevodu' => count($prevody), 'odvozeno' => 0, 'vlozeno_czk' => 0.0];
        if (!$prevody) return $shrnuti;

        $zaplaceno = $naBurzu - $zBurzy;
        $shrnuti['vlozeno_czk'] = round($zaplaceno, 2);

        // Rozdělovacím klíčem je tržní hodnota převodu — jediné, co o vzájemném
        // poměru převedených pozic z výpisu víme.
        $klicCelkem = 0.0;
        foreach ($prevody as $p) $klicCelkem += (float)($p['meta']['trzni_hodnota'] ?? 0);

        foreach ($prevody as $p) {
            $r = $p['radek'];
            $meta = $p['meta'];
            $klic = (float)($meta['trzni_hodnota'] ?? 0);

            if ($zaplaceno <= 0 || $klicCelkem <= 0) {
                // Odvodit nejde; ať je v datech vidět proč.
                $meta['basis_status'] = 'UNKNOWN';
                $meta['basis_poznamka'] = $zaplaceno <= 0
                    ? 'na tuto platformu nejsou zachycené peníze poslané na interní burzu'
                    : 'převod nemá tržní ocenění, podle kterého by šlo náklad rozdělit';
                ulozit_zaklad($pdo, (int)$r['trans_id'], null, $meta);
                continue;
            }

            $czk = $zaplaceno * ($klic / $klicCelkem);
            $meta['basis_status'] = 'ODVOZENY';
            $meta['basis_zdroj'] = 'interni_cashflow';
            $meta['basis_poznamka'] = 'odvozeno z peněz poslaných na interní burzu; '
                . 'skutečné obchody nejsou ve výpisu';
            unset($meta['basis_poznamka_stara']);

            ulozit_zaklad($pdo, (int)$r['trans_id'], [
                'czk' => $czk,
                'kurz' => (float)($r['ex_rate'] ?: 1),
                'mnozstvi' => abs((float)$r['amount']),
            ], $meta);

            $shrnuti['odvozeno']++;
        }
        return $shrnuti;
    }

    /**
     * Zapíše (nebo vynuluje) pořizovací cenu jednoho řádku.
     * @param array{czk:float,kurz:float,mnozstvi:float}|null $zaklad
     */
    function ulozit_zaklad(PDO $pdo, int $transId, ?array $zaklad, array $meta): void {
        $czk = $zaklad === null ? 0.0 : $zaklad['czk'];
        $kurz = ($zaklad === null || $zaklad['kurz'] <= 0) ? 1.0 : $zaklad['kurz'];
        $cur = $czk / $kurz;
        $mnozstvi = $zaklad === null ? 0.0 : $zaklad['mnozstvi'];

        $pdo->prepare(
            "UPDATE transactions
             SET amount_czk = ?, amount_cur = ?, price = ?, metadata = ?
             WHERE trans_id = ?"
        )->execute([
            round($czk, 2),
            round($cur, 8),
            $mnozstvi > 0 ? round($cur / $mnozstvi, 8) : 0,
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $transId,
        ]);
    }

    /** metadata jsou jsonb, ale starší řádky mohou nést i text nebo null. */
    function dekodovat_meta($hodnota): array {
        if (is_array($hodnota)) return $hodnota;
        if (!is_string($hodnota) || trim($hodnota) === '') return [];
        $d = json_decode($hodnota, true);
        return is_array($d) ? $d : [];
    }
}
