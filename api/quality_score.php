<?php
/**
 * Composite "quality" score (0–100) shown as the Odolnost/Kvalita column.
 *
 * Rewards a stable, long-standing, growing stock — with a small bonus for proven
 * "risen from the ashes" comebacks. Fresh tickers (little history) score 0.
 *
 *   base  = 35*growth + 35*stability + 20*longevity
 *   bonus = up to 10 for deep recovered crashes (resilience)
 *
 * @param float[] $prices chronological prices (oldest first)
 */
function quality_score(array $prices): int {
    $prices = array_values(array_filter(array_map('floatval', $prices), fn($p) => $p > 0));
    $years = count($prices) / 252.0;
    if ($years < 2) return 0; // no track record yet

    // Growth + stability over the last ~5 years (recent behaviour).
    $w  = count($prices) > 1260 ? array_slice($prices, -1260) : $prices;
    $wy = count($w) / 252.0;
    $cagr = ($wy > 0 && $w[0] > 0) ? pow($w[count($w) - 1] / $w[0], 1 / $wy) - 1 : 0.0;
    $growth = max(0.0, min($cagr / 0.15, 1.0));            // 15%/yr CAGR => full

    $mx = 0.0; $sum = 0.0; $n = 0;
    foreach ($w as $p) { if ($p > $mx) $mx = $p; if ($mx > 0) { $sum += $p / $mx; $n++; } }
    $stability = $n ? $sum / $n : 0.0;                      // avg closeness to running high

    $longevity = min($years / 12.0, 1.0);                  // full at ~12 years on market

    // Proximity to the all-time high: a stock trading far below its peak that never
    // recovered (e.g. AIG at ~4% of its pre-2008 high) is impaired, not quality.
    $ath = max($prices);
    $now = $prices[count($prices) - 1];
    $proximity = $ath > 0 ? max(0.0, min($now / $ath, 1.0)) : 0.0;

    // Resilience bonus: deep crashes (>=60% from ATH) that fully recovered, last ~12y.
    $rw = count($prices) > 3000 ? array_slice($prices, -3000) : $prices;
    $r = 0.0; $peak = 0.0; $inCrash = false; $crashPeak = 0.0; $trough = 0.0;
    foreach ($rw as $p) {
        if (!$inCrash) {
            if ($p > $peak) $peak = $p;
            if ($peak > 0 && ($peak - $p) / $peak >= 0.60) { $inCrash = true; $crashPeak = $peak; $trough = $p; }
        } else {
            if ($p < $trough) $trough = $p;
            if ($crashPeak > 0 && $p >= $crashPeak) { $r += ($crashPeak - $trough) / $crashPeak; $inCrash = false; if ($p > $peak) $peak = $p; }
        }
    }
    $bonus = min(($r * 100) / 12.0, 10.0);

    // ATH proximity acts as a GATE (multiplier), not just an additive term, so a
    // stock that rose, peaked and then permanently fell — never returning near its
    // high — is pulled down no matter how strong its recent growth looks.
    $base = 35 * $growth + 35 * $stability + 20 * $longevity + $bonus; // 0..100
    $factor = 0.30 + 0.70 * $proximity;                                // 1.0 at ATH, 0.3 if far below
    return (int) round($base * $factor);
}
