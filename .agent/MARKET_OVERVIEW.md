---
description: Market overview (Přehled trhu) data sources + the "Kvalita" quality score
---

# Market overview & the Kvalita score

Covers `MarketPage.tsx` + `api-market-data.php` + the metrics that feed them.
(Last updated 2026-06-02.)

## Universe
`live_quotes` holds every tracked ticker. It now includes the **S&P 500** (~503
symbols bulk-inserted; their GICS **sector**, **industry** and **company name** were
bulk-filled from the public constituents CSV — no Yahoo needed for those) plus the
user's held/watched tickers. `api-market-data.php` returns the UNION of
transactions + watch + all `live_quotes`.

## Where each column comes from
| Column (live_quotes) | Source |
|---|---|
| `price` / `current_price`, `change_*` | Yahoo **quote** (price fetch) |
| `sector`, `industry` | S&P CSV (bulk) for S&P names; otherwise Yahoo `quoteSummary` assetProfile (crumb). `saveQuote` keeps an existing sector instead of overwriting (GICS preserved). |
| `market_cap`, `pe_ratio`, `dividend_yield`, `ex_dividend_date` | Yahoo **quote** (crumb-authenticated) |
| `exchange` | Yahoo chart **meta** (saved during history fetch — no crumb) or the quote |
| `all_time_high/low`, `ema_212`, `high/low_52w`, `resilience_score` (Kvalita) | computed from `tickers_history` (history fetch / `calculate_metrics.php`) |
| `on_market_since` (returned by api, not stored) | `EXTRACT(YEAR FROM MIN(history_date))` |

CZK / accounting currency: `api-market-data.php` loads latest `rates` (fresh via
`ensure_current_rates`) + the user's `base_currency`, and adds `current_price_czk` /
`current_price_base` per row. **Single native price in the DB, converted on display
— no per-currency duplication.** (Same principle as transactions: `amount_czk` is the
CZK source of truth, `base_currency` is display-only.)

## Two batches that populate data
- **"Aktualizovat ceny"** (`ajax-update-prices` → `GoogleFinanceService::getQuote`):
  price, sector, market cap, P/E, dividend yield, exchange. Per-ticker Yahoo crumb
  handshake — heavy for ~500 names.
- **"Aktualizovat historii"** (`ajax-fetch-history.php`): fetches `tickers_history`
  (Yahoo chart) and computes ATH/ATL/EMA/Kvalita. Also:
  - **skips a ticker that already has the requested span** (for `max`, ≥~12y of
    history) — so a full-history run only fetches missing/new names;
  - **backfills missing fundamentals** (dividend yield/market cap/P-E/exchange) via
    `getQuote`, but only for tickers that lack them (runs even when history is cached).

> Note: dividend YIELD here is the market figure from Yahoo. The **Dividendy page**
> (`api-dividends.php`) is different — it sums the user's actually-received dividends
> from imported `transactions`.

## The "Kvalita" score — `api/quality_score.php`
Stored in `live_quotes.resilience_score`, shown as the **Kvalita** column (0–100,
green ≥70 / orange ≥45 / else grey). Computed from price history (+ dividend yield).
It rewards a **stable, long-standing company that is at/near its peak or climbing
back**, and penalizes one that **rose, peaked and then stayed down**. Fresh tickers
(<2y history) score 0.

```
base   = 35*growth + 35*stability + 20*longevity + resilienceBonus(≤10)   // 0..100
health = max( ATH_proximity , 0.65*recovery_momentum )   // near its high OR climbing back
score  = round( base * (0.30 + 0.70*health) + dividendBonus )             // dividendBonus = min(yield%, 6)
```
- **growth** = 5y CAGR, full at 15%/yr.
- **stability** = avg(price / running-high) over ~5y (how smooth/near-highs it is).
- **longevity** = years on market, full at ~12y.
- **resilience bonus** = sum of depths of deep (≥60%) crashes that fully recovered to
  the prior ATH, ×100/12, capped 10 ("risen from the ashes").
- **ATH proximity** = current / all-time-high. Acts as a **gate (multiplier)** so a
  permanently-impaired stock (AIG at ~4% of its peak) is pulled down regardless of
  recent growth.
- **recovery momentum** = 1-year price change, full at +30% — lets a stock climbing
  back from a fall (AES) count as healthy, and keeps a stable-near-ATH name (META)
  from being punished for being flat.
- **dividend bonus** = small additive reward for paying a dividend.

Reference points (2026-06-02): AAPL 87, XOM 86, META 75, AES 38, AIG 28, NIO 22.
**Tuning** = adjust the weights/thresholds in `quality_score.php`; it is the single
source of truth, called by both `calculate_metrics.php` and `ajax-fetch-history.php`.
After changing it, re-run `calculate_metrics.php` (or re-fetch history) to recompute.
