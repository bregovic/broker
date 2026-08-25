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

    /**
     * Ocení dividendy vyplacené v akciích.
     *
     * CTP vyplácí volitelnou dividendu: místo peněz přijdou na účet kusy akcií
     * a výpis u nich neuvádí žádnou částku. V portfoliu pak ležely s nulovou
     * pořizovací cenou (u tohohle účtu 27 kusů z celkových 276) a v dividendách
     * nebyly vidět vůbec — přitom jde o příjem jako každý jiný.
     *
     * Ocení se kurzem v den připsání a ta částka slouží dvakrát: jako pořizovací
     * cena nových kusů a zároveň jako dividendový příjem. Ekonomicky je to
     * totéž, jako by přišla hotovost a hned se za ni akcie koupily — a právě
     * proto se to nedvojí: co je příjem, je zároveň náklad pozice.
     *
     * @return array{radku:int,ocenenych:int,celkem_czk:float,bez_ceny:int}
     */
    function ocenit_dividendy_v_akciich(PDO $pdo, int $userId): array {
        $stmt = $pdo->prepare(
            "SELECT trans_id, date, ticker, amount, currency, ex_rate, platform,
                    broker_trade_id, metadata
             FROM transactions
             WHERE user_id = ? AND UPPER(trans_type) = 'REVENUE' AND amount > 0
               AND (metadata->>'dividenda_v_akciich' = 'true'
                    OR (metadata->>'popis' ILIKE '%dividend%' AND metadata->>'popis' ILIKE '%(akcie)%'))
             ORDER BY date"
        );
        $stmt->execute([$userId]);
        $radky = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $shrnuti = ['radku' => count($radky), 'ocenenych' => 0, 'celkem_czk' => 0.0, 'bez_ceny' => 0];

        foreach ($radky as $r) {
            $meta = dekodovat_meta($r['metadata']);
            $cena = historicka_cena($pdo, (string)$r['ticker'], (string)$r['date']);

            if ($cena === null) {
                $shrnuti['bez_ceny']++;
                $meta['basis_status'] = 'UNKNOWN';
                $meta['basis_poznamka'] = 'k datu připsání nemáme historickou cenu papíru';
                $pdo->prepare("UPDATE transactions SET metadata = ? WHERE trans_id = ?")
                    ->execute([json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$r['trans_id']]);
                continue;
            }

            $mnozstvi = abs((float)$r['amount']);
            $kurz = (float)($r['ex_rate'] ?: 1);
            $cur = $cena * $mnozstvi;          // v měně obchodu
            $czk = $cur * $kurz;

            $meta['basis_status'] = 'ODVOZENY';
            $meta['basis_zdroj'] = 'trzni_cena_v_den_pripsani';
            $meta['cena_v_den_pripsani'] = round($cena, 4);

            $pdo->prepare(
                "UPDATE transactions SET price = ?, amount_cur = ?, amount_czk = ?, metadata = ?
                 WHERE trans_id = ?"
            )->execute([
                round($cena, 8), round($cur, 8), round($czk, 2),
                json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$r['trans_id'],
            ]);

            // Protějšek v dividendách. `api-dividends.php` bere `trans_type`
            // DIVIDEND s nulovým množstvím, ať se z toho nestane druhá pozice.
            $otisk = ($r['broker_trade_id'] ?: 'FIO_AKCDIV_' . $r['trans_id']) . ':div';
            $uz = $pdo->prepare("SELECT 1 FROM transactions WHERE user_id = ? AND broker_trade_id = ?");
            $uz->execute([$userId, $otisk]);
            if (!$uz->fetchColumn()) {
                $pdo->prepare(
                    "INSERT INTO transactions
                       (user_id, date, ticker, trans_type, amount, price, currency, fees,
                        amount_czk, ex_rate, amount_cur, platform, product_type, broker_trade_id,
                        transaction_date, type, quantity, price_per_unit, fee, total_amount,
                        source_broker, metadata)
                     VALUES (?,?,?,'DIVIDEND',0,0,?,0,?,?,?,?,'Stock',?,?,'DIVIDEND',0,0,0,?,?,?)"
                )->execute([
                    $userId, $r['date'], $r['ticker'], $r['currency'], round($czk, 2), $kurz,
                    round($cur, 8), $r['platform'], $otisk, $r['date'], round($cur, 8),
                    $r['platform'],
                    json_encode([
                        'nazev' => $meta['nazev'] ?? null,
                        'popis' => 'Dividenda vyplacená v akciích, oceněná kurzem v den připsání',
                        'dividenda_v_akciich' => true,
                        'zdroj_radku' => (int)$r['trans_id'],
                        'cena_v_den_pripsani' => round($cena, 4),
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }

            $shrnuti['ocenenych']++;
            $shrnuti['celkem_czk'] += $czk;
        }
        $shrnuti['celkem_czk'] = round($shrnuti['celkem_czk'], 2);
        return $shrnuti;
    }

    /**
     * Závěrečná cena papíru k datu. Připsání často padne na den, kdy se
     * neobchodovalo, proto se bere nejbližší předchozí kurz do týdne zpět.
     */
    function historicka_cena(PDO $pdo, string $ticker, string $datum): ?float {
        $stmt = $pdo->prepare(
            "SELECT price FROM tickers_history
             WHERE ticker = ? AND history_date <= ? AND history_date >= ?::date - INTERVAL '7 days'
               AND price > 0
             ORDER BY history_date DESC LIMIT 1"
        );
        $stmt->execute([$ticker, $datum, $datum]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (float)$v;
    }

    /** metadata jsou jsonb, ale starší řádky mohou nést i text nebo null. */
    function dekodovat_meta($hodnota): array {
        if (is_array($hodnota)) return $hodnota;
        if (!is_string($hodnota) || trim($hodnota) === '') return [];
        $d = json_decode($hodnota, true);
        return is_array($d) ? $d : [];
    }
}
