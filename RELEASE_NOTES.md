# Broker 2.0 - Development History & Release Notes

## [Unreleased] - 2026-08-24 (b)
### Fixed — Revolut crypto & commodity imports never ran at all
Verified by replaying rule discovery against the real statements in `example/`:
**every** Revolut crypto and commodity file (9/9 tested, PDF and CSV) was claimed by
the `revolut_trading_pdf` rule and handed to the trading parser.
- **Root cause**: `ImportManager::discoverRule()` returned the *first* matching row of
  `broker_import_rules` with no ordering, and the trading rule matched on generic words
  (`Výpis z účtu`, `Obchod`, `Dividend`) plus a `file_pattern` of `account-statement`,
  which is a substring of `crypto-account-statement`. The crypto/commodity rules matched
  too, but were never reached.
- Rules are now evaluated in `priority` order (lowest first). Priority comes from a new
  `broker_import_rules.priority` column when present, otherwise it is derived from the
  config name — so discovery is fixed on deploy, before `init_broker.php` is re-run.
- Seeded regexes tightened: the trading rule now requires a real trading marker
  (`Transakce v USD`, `Obchod - Market`, …) and `ibkr_pdf` no longer matches the bare
  word `Transactions`. Fixed `kryptomĕnami`/`Smĕněno` (ĕ, breve) → `[ěĕ]`; the seeded
  spelling never matched a real statement.
- Added the missing `ibkr_activity_csv` and `fio_csv` rules to the seed — `ibkr_activity_csv`
  existed only as a hand-made row in the production DB, so a fresh environment lost it.

### Fixed — Revolut crypto parser read the wrong column layout
- In a crypto statement the **date is the last column** of each row
  (`Symbol Typ Množství Cena Hodnota Poplatky Datum`). The parser split the text into
  date-*led* blocks, so every transaction was stamped with the **previous** row's date,
  and its trade regex expected `Nákup BTC` when the statement writes `BTC Nákup`.
- Rewritten to match whole rows anchored on symbol+type at the start and the date at the
  end. On the reference statement it now returns **171/171 rows** (27 buys, 8 sells,
  9 card payments in crypto, 127 staking rewards); it previously returned 127 rewards with
  shifted dates and 2 accidental trades. Verified against three separate statements.
- Card payments settled in crypto (`Platba`) are recorded as disposals (`SELL`) — they
  realize a gain the same way a sale does. Fees are now carried into the DTO.

### Fixed — `parseNumber` turned `0,275` into `275`
- `AbstractParser::parseNumber` treated "exactly 3 digits after a comma" as a thousands
  group, so a crypto quantity of `0,275 ETH` parsed as `275 ETH`. A thousands group is
  never preceded by a bare zero; that case is now a decimal separator.

### Verified, not changed
- `RevolutCommodityPdfParser` was correct all along — it just never got called. Running it
  on the real XAU statement yields all 3 transactions with the right quantities, currencies
  and amounts (closing position matches the statement's own summary exactly).

## [Unreleased] - 2026-08-24
### Fixed — Balance: 4 columns were dead (Avg cost / P&L orig / P&L % orig / FX P&L)
- `BalancePage.tsx` rendered `avg_cost_orig`, `unrealized_orig`, `unrealized_pct_orig`
  and `fx_pnl_czk`, but `api-portfolio.php` never emitted those fields → the columns
  showed `-` / `0` / `0.00 %` / `0` for every position. `total_cost_orig` was already
  being accumulated in the aggregation and then silently discarded.
- `api-portfolio.php` now computes them (plus `avg_cost_czk`):
  - `avg_cost_orig = total_cost_orig / net_qty`
  - `unrealized_orig = (current_price_in_cost_currency - avg_cost_orig) * net_qty`
  - `fx_pnl_czk = unrealized_czk - unrealized_orig * current_rate` — i.e. the CZK
    result minus the price move valued at today's rate, so what's left is the
    currency move on the cost basis.
- **Cost basis unified on one source.** `total_cost_orig` now derives from
  `amount_cur` (was `amount * price`). Since `amount_czk = amount_cur * ex_rate`, the
  original-currency and CZK cost bases can no longer disagree and the FX split is
  exact. Fees are therefore counted exactly as the broker booked them into the
  transaction amount and are *not* added on top — matching `api-pnl.php`, which
  keeps `fees` as a separate term in the realized result. `amount * price` remains
  as a fallback for parsers that leave `amount_cur` empty.
- **Quote currency is now respected.** `live_quotes.currency` was selected but never
  used: the current price was converted with the *transaction* currency's rate. IBKR
  books US stocks in CZK, so those positions were valued with rate 1 on a USD price.
  The quote's own currency is used when a rate exists for it, else the old fallback.
- Positions that mix cost currencies (grouping by ticker across platforms) return
  `null` for the original-currency columns instead of a meaningless number; the grid
  renders `—`.

## [Unreleased] - 2026-05-29
### Fixed — `trans_type` case mismatch (Dividends & P&L were empty)
- Production data stores `trans_type` in UPPERCASE (`DIVIDEND`, `BUY`, `SELL`),
  but the API filtered Title-case literals (`'Dividend'`, `'Buy'`, `'Sell'`) →
  every filtered query returned 0 rows.
- `api-dividends.php`: filter via `UPPER(trans_type) IN (...)`; normalize the
  `type` returned to the frontend to canonical Title-case.
- `api-pnl.php`: match `UPPER(trans_type)` for Buy/Sell.
- Verified against the live DB: 428 dividends and 47 sales now visible (was 0).
- Follow-up (not yet done): `ajax-check-missing-prices.php` and several legacy
  flat-PHP files (`div.php`, `bal.php`, `sal.php`) still use case-sensitive
  filters; the legacy ones query a non-existent `broker_trans` and are dead.

## [Unreleased] - 2026-05-29 (b)
### Fixed — legacy endpoints couldn't connect on Railway (hardcoded MySQL)
- Several frontend-wired endpoints built a hardcoded `new PDO("mysql:...")` and
  relied on a non-existent `api/db.php` → broken on Railway PostgreSQL.
- `ajax-get-chart-data.php`: use `get_pdo()`; fix column `date` → `history_date`
  (verified: returns 502 rows for a real ticker).
- `api-delete-transactions.php`: use `get_pdo()`; fix ticker filter `id` → `ticker`.
- Known remaining: `ajax-update-prices` (id/ticker drift), `api-comments` /
  `ajax-get-user` / `api-dev-history` target tables that don't exist in the
  production schema (`changerequest_*`, `development_history`) — needs schema
  decision before fixing. ~23 dead `broker_*` legacy files pending cleanup.

## [Unreleased] - 2026-05-29 (c)
### Fixed — Helpdesk & Dev History were non-functional on Railway (missing schema)
- The production PostgreSQL DB never had the helpdesk/dev-history tables (only
  core trading tables existed), so RequestsPage / comments / dev-history failed.
- Added `api/sql/helpdesk_schema.sql` — idempotent PG schema for
  `changerequest_log`, `_attachments`, `_history`, `_comments`,
  `_comment_attachments`, `_comment_reactions`, and `development_history`
  (ported from the MySQL `setup_*.php` scripts + consolidated ALTERs).
  Applied to the live Railway DB; all 7 tables verified.
- `api-comments.php`: `get_pdo()` + `GROUP_CONCAT(... SEPARATOR)` → `string_agg`.
- `api-dev-history.php`: `get_pdo()` + `DATE_FORMAT` → `to_char`; added `date` column.
- `ajax-get-user.php`: `get_pdo()` (both connection sites).
- `api-changerequests.php` already used `get_pdo()`; works now that tables exist.

## [Unreleased] - 2026-05-29 (d)
### Fixed — watchlist toggle & price refresh (last broken frontend endpoints)
- `ajax-update-prices.php`: `get_pdo()`; fixed currency subquery
  `transactions.id` → `transactions.ticker` (verified on live DB).
- `ajax-toggle-watch.php`: rewritten to the real contract — `POST {ticker}`
  toggles a row in the `watch` table for the session user (was updating a
  non-existent `broker_live_quotes.track_history`). Mirrors the proven
  `toggle` action in `ajax-manage-watchlist.php`.

## [Unreleased] - 2026-05-29 (e)
### Fixed — import mangled thousands separators (P&L showed fake losses)
- `AbstractParser::parseNumber` always did `str_replace(',', '.')`, so a US-format
  amount like `1,267.00` became `1.267` — values ≥ 1000 were imported ~1000× too
  small. This corrupted large SELL amounts → P&L showed huge fabricated losses
  (e.g. INTC sold at "$0.036" instead of "$36"). Buys/dividends under 1000 were fine.
- parseNumber now detects US (`1,267.00`) vs European (`1.267,50`) formats by
  separator position and treats a lone comma with 3 trailing digits as thousands.
- Re-import the affected statements to correct the data.

## [Unreleased] - 2026-05-29 (f)
### Added — on-demand FX rate sync for valuations
- `rate_sync.php`: `ensure_current_rates()` pulls the ČNB daily fixing into
  `rates` when the newest stored rate is stale (cached — only the first request
  of the day hits ČNB; fails silently if unreachable).
- `api-portfolio.php` calls it so the Balance page values holdings with a current
  CZK rate instead of the last imported one (e.g. USD 21.333 from March → 20.851).

## [Unreleased] - 2026-05-29 (g)
### Added — ticker aliases (unify renamed symbols in reports)
- New `ticker_aliases (alias -> canonical)` table; seeded GOLD -> B (Barrick Gold
  ticker change). Created in prod + added to `init_broker.php`.
- `api-portfolio.php`, `api-pnl.php`, `api-dividends.php` now resolve the report
  ticker via `LEFT JOIN ticker_aliases ... COALESCE(canonical, ticker)`, so a
  renamed security is aggregated as one holding and P&L matches sells under the
  new ticker to buys under the old one. Verified: GOLD+B unify under B.
- Follow-up: auto-detect aliases from statement ISIN (pending ISIN-in-data check).

## [v2.1.0] - 2026-03-31
### Modernization & Railway Deployment
- **Core Refactoring**: Completely overhauled the backend to support PostgreSQL and environment-based configuration via `DATABASE_URL`.
- **Infrastructure**: Added `Dockerfile` and `nginx.conf` for containerized deployment.
- **Directory Structure**: 
  - Backend moved to `/api/`
  - Frontend moved to `/frontend/` (React/Vite)
- **Database Architecture**:
  - Implemented `api/config.php` as a central DB adapter.
  - Added `api/init_broker.php` for easy schema initialization on new environments.
  - Switched from MySQL-specific syntax to cross-driver PDO.
- **Frontend Updates**:
  - Updated API integration to use the new `/api/` prefix.
  - Implemented `AuthContext` and `TranslationContext` with PostgreSQL support.
- **Initialization**: Created a robust setup script that creates necessary tables and a default admin user.

## [v2.0.0] - Legacy Implementation
- Original implementation with MySQL on Wedos.
- Built using PHP 7.4 and React (legacy build).
- Integrated with ČNB for currency rates.
- Basic portfolio and transaction tracking.
