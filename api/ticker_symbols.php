<?php
/**
 * Tickery z pražské burzy → symboly na Yahoo Finance.
 *
 * Fio (a naše `TickerMap`) používá kódy z BCPP, Yahoo k nim chce příponu `.PR`
 * — jenže ne vždy jde jen o příponu: Colt CZ je na Yahoo `COLT`, CTP je `CTPNV`.
 * Bez téhle mapy se české papíry nikdy neocení a v Bilanci u nich zůstane
 * nulová aktuální cena.
 *
 * Ověřeno proti Yahoo dne 24. 8. 2026 — všechny symboly níže vracely živou cenu
 * v CZK. Papíry bez pokrytí jsou uvedené v BEZ_ZDROJE, ať se po nich zbytečně
 * nešahá znovu.
 */

if (!function_exists('bcpp_yahoo_symbol')) {

    /** Ticker (BCPP) → symbol na Yahoo. Klíč i hodnota ověřeny živým dotazem. */
    define('BCPP_YAHOO', [
        'CEZ'   => 'CEZ.PR',    'KOMB'  => 'KOMB.PR',   'MONET' => 'MONET.PR',
        'TABAK' => 'TABAK.PR',  'ERBAG' => 'ERBAG.PR',  'VIG'   => 'VIG.PR',
        'KOFOL' => 'KOFOL.PR',  'TMR'   => 'TMR.PR',    'PRIUA' => 'PRIUA.PR',
        'GEV'   => 'GEV.PR',    'DSPW'  => 'DSPW.PR',   'EFORU' => 'EFORU.PR',
        'ENRGA' => 'ENRGA.PR',  'FTSHP' => 'FTSHP.PR',  'PEN'   => 'PEN.PR',
        'PVT'   => 'PVT.PR',    'SABFG' => 'SABFG.PR',  'TOMA'  => 'TOMA.PR',
        'BEZVA' => 'BEZVA.PR',  'EMAN'  => 'EMAN.PR',   'FILL'  => 'FILL.PR',
        'FIXED' => 'FIXED.PR',  'HWIO'  => 'HWIO.PR',   'KARIN' => 'KARIN.PR',
        'KLIKY' => 'KLIKY.PR',  'M2C'   => 'M2C.PR',    'MMCTE' => 'MMCTE.PR',
        'PINK'  => 'PINK.PR',   'PRAB'  => 'PRAB.PR',
        // Kde se symbol liší víc než příponou:
        'CZG'   => 'COLT.PR',   // Colt CZ Group
        'CTP'   => 'CTPNV.PR',  // CTP N.V.
    ]);

    /**
     * Papíry, ke kterým Yahoo cenu nemá — nemá smysl je zkoušet.
     * O2 C.R. bylo v roce 2021 vytěsněno z burzy (PPF), takže žádná aktuální
     * cena neexistuje; ATOMT a COLOS jsou z trhu START bez pokrytí.
     */
    define('BCPP_BEZ_ZDROJE', ['O2', 'ATOMT', 'COLOS']);

    /** Vrátí symbol pro Yahoo, nebo null, pokud ticker není z pražské burzy. */
    function bcpp_yahoo_symbol(string $ticker): ?string {
        return BCPP_YAHOO[strtoupper(trim($ticker))] ?? null;
    }

    /**
     * Měna kotace pro papír z pražské burzy — vždy CZK.
     *
     * Zdroji se v tomhle nedá věřit: Yahoo hlásí u `CTPNV.PR` měnu EUR, protože
     * primární listing CTP je v Amsterdamu, ačkoli cena 349,20 je v korunách.
     * Protože `api-portfolio.php` podle měny přepočítává, ocenilo se 281 kusů
     * na 2 364 817 Kč místo 98 000 — přesně kurzem eura. Ověřeno živě u všech
     * 31 tickerů z mapy: kotace jsou v CZK.
     */
    function bcpp_mena(string $ticker): ?string {
        return bcpp_yahoo_symbol($ticker) !== null ? 'CZK' : null;
    }

    /** True pro papíry, u kterých víme, že cenu nedohledáme. */
    function bcpp_bez_zdroje(string $ticker): bool {
        return in_array(strtoupper(trim($ticker)), BCPP_BEZ_ZDROJE, true);
    }
}
