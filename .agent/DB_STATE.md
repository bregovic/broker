---
description: Verified state of the production Railway PostgreSQL DB + known schema gaps
---

# Production DB State & Known Issues

> Verified live against Railway PostgreSQL 18.3 (db `railway`) on **2026-05-29**.
> Re-verify before relying on this — schema may change.

## Tables that EXIST in production (18, core trading only)
`transactions` (630) · `tickers_history` (25 928) · `rates` (9 671) · `live_quotes` (61) ·
`watch` (75) · `translations` (53) · `users` (6) · `user_settings` (3) · `brokers` (7) ·
`import_staging` (7) · `asset_types` (6) · `asset_classes` (4) · `broker_import_rules` (3) ·
`system_config` (2) · `currencies` (0) · `dividends` (0) · `dividend_history` (0) · `ticker_mapping` (0)

## ⚠️ Tables the code expects but that DO NOT exist in production
- **Helpdesk**: `changerequest_comments`, `changerequest_history`, `changerequest_log`,
  `changerequest_attachments`, `changerequest_comment_*` → **RequestsPage / api-changerequests /
  api-comments are non-functional in prod.** Created by `setup_changerequests_db.php` etc.,
  which were never run on Railway. (`init_broker.php` only bootstraps core trading tables.)
- **Dev history**: `development_history` → `api-dev-history` is dead in prod.
- **Legacy `broker_*`**: `broker_trans`, `broker_live_quotes`, `broker_watch`, … (24 files
  reference these). They were renamed to the non-prefixed tables during the PG migration but
  the old code was never updated. All such files are effectively dead.

## 🔴 OPEN: USD amounts never converted to CZK (affects Portfolio, P&L, Dividends)
All USD transactions have `ex_rate = 1` and `amount_czk = amount_cur` — the importer
never applied the USD→CZK rate. So CZK totals are ~22× too low for USD assets
(e.g. dividends show 4 443 "Kč" but it's 4 443 USD ≈ ~98 000 CZK). Scope (user 5):
DIVIDEND 356/356, BUY 124/138, SELL 26/45 unconverted. USD rates exist in `rates`
(2025-01-01…2026-03-30). Fix = backfill `ex_rate`/`amount_czk` from `rates` (by date)
AND fix the importer so it converts. **Pending user go-ahead (changes portfolio numbers).**

## Market metrics (high/low, EMA, ATH, resilience)
- `calculate_metrics.php` (batch) computes 52w high/low + EMA 212 from `tickers_history`
  → fixed for PG (get_pdo, history_date, idempotent ALTERs) and restored. Run it via
  `/api/calculate_metrics.php` to populate `live_quotes`.
- `ajax-fetch-history.php` computes `all_time_high/low` + `resilience_score` lazily,
  per-ticker, when a ticker's history is opened — so most are NULL until viewed.

## Data conventions (verified)
- **`trans_type` is stored UPPERCASE**: `DIVIDEND` (428), `BUY` (155), `SELL` (47).
  Always compare case-insensitively: `UPPER(trans_type) IN ('DIVIDEND', ...)`.
  (`type` mirror column is also uppercase.)
- **`product_type` is Title-case**: `Stock`. 
- `transactions` has **no `id` column** for tickers — use `ticker`. (Some legacy code still
  filters/join on `id`; that is a bug.)
- `transactions` carries **two column sets** (legacy: `trans_type, amount, price, fees, platform,
  date`; v3 import: `type, quantity, price_per_unit, fee, total_amount, source_broker,
  transaction_date, metadata`) — both populated for all rows. Decide a canonical set eventually.
- `tickers_history` date column is **`history_date`** (not `date`).
- `live_quotes` PK is `ticker` (it also has a trailing `id` column).

## Two generations of backend in `/api/`
| | Modern `api-*.php` | Legacy `ajax-*.php` + flat PHP |
|---|---|---|
| Connection | `get_pdo()` (config.php) ✅ | hardcoded `new PDO("mysql:...")` + non-existent `api/db.php` ❌ |
| Tables | current (`transactions`, `live_quotes`) | stale (`broker_*`) ❌ |
| Status | works | broken / dead |

## Fix backlog (status as of 2026-05-29)
- [x] `api-dividends.php`, `api-pnl.php` — case-insensitive `trans_type`.
- [x] `ajax-get-chart-data.php` — `get_pdo()` + `history_date`.
- [x] `api-delete-transactions.php` — `get_pdo()` + ticker filter.
- [x] `ajax-update-prices.php` — `get_pdo()` + `transactions.id` → `ticker`.
- [x] `ajax-toggle-watch.php` — `get_pdo()` + rewritten to toggle the `watch` table.
- [x] Helpdesk/dev-history schema created in prod (`api/sql/helpdesk_schema.sql`).
- [x] `api-comments.php` (get_pdo + string_agg), `api-dev-history.php` (get_pdo + to_char,
      added `date` col), `ajax-get-user.php` (get_pdo). `api-changerequests.php` already OK.
- [x] Cleanup: deleted ~70 dead files (legacy flat-PHP `bal/sal/div/market/portfolio/broker`,
      `debug_/diag_/fix_/cleanup_/migrate_/rename_/restore_/check_/setup_*` one-offs, legacy
      `import.php`/`csv-import.php`, versioned `add_market_trans_v*`). All verified: not
      included by live code, not called by the frontend. Recoverable via git history.
      **Kept (verify before touching):** `agent_api.php`, `workflow_executor.php`,
      `cnb-import.php`, `deploy_hook.php` (possible external/cron callers).
- [ ] `rates.php` is a hybrid: `?api=1` returns JSON (used by import) — its legacy HTML
      branch still links to deleted pages (cosmetic; React never loads it). Tidy later.
- [ ] Consolidate `init_broker.php` so a fresh environment bootstraps the FULL intended schema.
