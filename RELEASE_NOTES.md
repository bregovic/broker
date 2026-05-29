# Broker 2.0 - Development History & Release Notes

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
