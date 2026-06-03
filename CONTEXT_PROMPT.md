# Investyx (Broker 2.0) — Context Prompt

> **Single source of truth.** Always read this document before working on the project.
> Supporting standards live in `.agent/`:
> - [`.agent/FORM_STANDARD.md`](.agent/FORM_STANDARD.md) — UI, forms & mobile layout rules.
> - [`.agent/SOP.md`](.agent/SOP.md) — how to make a change safely (workflow + checklist).
> - [`.agent/DB_STATE.md`](.agent/DB_STATE.md) — **verified prod schema, data conventions & known gaps. Read before touching the DB.**
> - [`.agent/MARKET_OVERVIEW.md`](.agent/MARKET_OVERVIEW.md) — Market overview data sources, the S&P 500 universe & the **Kvalita** quality score.
>
> _Last reviewed: 2026-06-02. Keep this header date current when you make structural changes._

## Project Summary
**Investyx** is a focused trading / portfolio-management app (stocks, crypto, dividends, P&L, multi-broker imports). It is the actively developed product (repo `bregovic/broker.git`, Railway project "Investyx 2.0"). It was modernized from a legacy Wedos/MySQL app to **Railway + PostgreSQL + Docker**, and still keeps MySQL compatibility for local/legacy use.

> Sibling project **SHANON** (`bregovic/shanon.git`, the `broker 3.0 (railway)` folder) is a separate, broader ERP platform whose development stalled in March 2026. We may selectively port good *framework* pieces from it (forms, mobile, settings, keyboard shortcuts) — but Investyx stays a trading app, not an ERP.

## Architecture Guidelines
- **Backend (API)**: Native PHP in `/api/`. No framework. Entry points are `api-*.php` (current) and `ajax-*.php` (legacy).
- **Frontend**: React 19 + Vite 7 + TypeScript + **Fluent UI v9** in `/frontend/`.
- **Database**:
  - Use `get_pdo()` from `api/config.php` for **all** DB connections. Never hardcode credentials.
  - `config.php` supports both **PostgreSQL** (Railway, via `DATABASE_URL`) and **MySQL** (legacy/local via `env.local.php`). Write **cross-driver** SQL.
  - Use `ON CONFLICT` for Postgres; detect the driver via `PDO::ATTR_DRIVER_NAME` when syntax must differ.
- **Routing**: Frontend is a SPA. Nginx serves the React build at `/` and proxies `/api/*` to PHP. Always call the backend with the `/api/` prefix.

## Project Structure
```
/api/            PHP backend (REST-ish endpoints, no framework)
  config.php       DB adapter — get_pdo(), MySQL+PostgreSQL
  init_broker.php  Schema bootstrap / seed (run on new environments)
  db_repair.php    Self-healing: registers tickers, fixes missing columns
  api-*.php        Current endpoints (login, portfolio, transactions, pnl, dividends, …)
  ajax-*.php       Legacy endpoints (prices, watchlist, charts)
  v3/Import/       Object-oriented import layer (see "Import Architecture")
  js/parsers/      Legacy JS parser implementations (being consolidated)
/frontend/       React + Vite + TypeScript + Fluent UI v9
  src/pages/       One file per route
  src/context/     AuthContext, SettingsContext, TranslationContext
  src/utils/parsers/  Client-side TS parsers (used by ImportPage)
Dockerfile       2-stage build (Vite build → php-nginx production image)
nginx.conf       Serves SPA at /, proxies /api
```

### Frontend Routes / Modules
`market` (default) · `portfolio` · `dividends` · `pnl` · `rates` · `balance` · `import` · `requests` (helpdesk) · plus `login` / `register`. One page component per route in `frontend/src/pages/`.

## Deployment
- **Trigger**: push to `main`.
- **Railway** builds the `Dockerfile` (stage 1: `npm ci && npm run build` → `frontend/dist`; stage 2: `trafex/php-nginx`, copies `api/` → web root and `dist/` → `public/`, serves on port 8080). DB is PostgreSQL via `DATABASE_URL`.
- A GitHub Actions workflow also mirrors a build to FTP (`hollyhop.cz/investyx`) as backup.
- **Before pushing**: run `cd frontend && npm run build` — it must pass (`tsc -b && vite build`).

## Database Schema – Key Tables

### `transactions`
| Column | Notes |
|--------|-------|
| `trans_id` | Primary key (SERIAL) |
| `user_id` | User FK |
| `date` | Transaction date |
| `id` | Ticker symbol (legacy column, used by import-handler) |
| `ticker` | Ticker symbol (newer column, may coexist with `id`) |
| `amount` | Quantity of shares/units |
| `price` | Price per unit |
| `ex_rate` | Exchange rate at time of transaction |
| `amount_cur` | Total value in original currency |
| `currency` | Original currency (USD, EUR, CZK...) |
| `amount_czk` | Total value converted to CZK |
| `platform` | Broker/platform name |
| `product_type` | Stock, Crypto, Cash, Fee, Tax, FX |
| `trans_type` | **See values below** |
| `fees` | Associated fees |
| `notes` | Free-text notes |
| `fingerprint` | SHA256 dedup hash |

#### `trans_type` Values
- `Buy`, `Sell` – Stock/Crypto trades
- `Dividend` – Dividend payment (positive)
- `Withholding` – Withholding tax on dividends (negative; rarely used by parsers)
- `Tax` – Withholding/foreign tax (used by IBKR parser)
- `Deposit`, `Withdrawal` – Cash movements
- `Fee` – Custody/platform fees
- `Revenue` – Staking/crypto rewards
- `Other` – Corporate actions, spinoffs
- `Corporate Action`, `FX` – Special types

> **IMPORTANT**: When filtering dividend-related transactions, always use:
> `trans_type IN ('Dividend', 'Withholding', 'Tax')`

> **IMPORTANT**: When reading ticker from `transactions`, always use:
> `COALESCE(ticker, id) AS ticker` for compatibility with both old and new data.

### `user_settings`
| Column | Notes |
|--------|-------|
| `user_id` | Primary key |
| `lang` | `cs` / `en` |
| `theme` | `light` / `dark` |
| `base_currency` | `CZK`, `USD`, `EUR` – user's preferred display currency |

> **IMPORTANT**: Always respect `base_currency` from `user_settings` (default `CZK`).
> Use exchange rates from `rates` table to convert CZK amounts to the user's base currency.

### `rates`
- Latest rate per currency: `SELECT r.currency, r.rate, r.amount FROM rates r INNER JOIN (SELECT currency, MAX(date) as max_date FROM rates GROUP BY currency) m ON r.currency = m.currency AND r.date = m.max_date`
- Rate represents CZK per 1 unit of foreign currency (or per `amount` units).
- To convert CZK → base: `amount_czk / rate_of_base_currency`

### `live_quotes`
- Current market prices per ticker
- `id` / `ticker` column is the ticker symbol
- `price` / `current_price` for current value

## Import Architecture
Broker statements (PDF/CSV) are turned into `transactions`. There are currently **three** parser families — consolidating these is active work (see Current Focus):

1. **`api/v3/Import/` — object-oriented PHP layer (the target).** Wired into `api/v3/api-import.php` via `new ImportManager($db)`.
   - `AbstractParser` → `Csv/AbstractCsvParser`, `Pdf/*PdfParser`; output normalized through `TransactionDTO`.
   - Implemented: Fio (CSV), IBKR (PDF), Revolut trading/crypto/commodity (PDF).
   - **Convention for a new broker**: add a parser class under `Csv/` or `Pdf/` extending the right abstract base, return `TransactionDTO`s, and register it in `ImportManager`.
2. **`frontend/src/utils/parsers/` — client-side TS parsers** (used by `ImportPage.tsx`): Revolut (+ crypto/commodity), IBKR, Trading212, Fio, Coinbase.
3. **`api/js/parsers/` — legacy JS** (`ParserFactory`, `BaseParser`, …). Being retired in favor of (1).

> **Goal**: backend `v3/Import` is the canonical engine. When adding/fixing a broker, prefer extending the v3 PHP layer; only touch TS/JS parsers for parity until they are removed.

## Critical Workflows
See [`.agent/SOP.md`](.agent/SOP.md) for the full step-by-step. In short:
- **New feature / fix**: make the change → `npm run build` must pass → record it in `RELEASE_NOTES.md` → commit (Conventional Commits) → push `main` (deploys).
- **Schema changes**: keep cross-driver (MySQL + PostgreSQL); reflect new tables/columns in `api/init_broker.php` so fresh environments bootstrap correctly.
- **Labels / i18n**: the frontend loads labels from **`api/v3/api-labels.php`, which reads the JSON files `api/v3/translations/{cs,en}.json`** — add new keys there (NOT only the DB `translations` table, which `api-translations.php` serves but the app doesn't use for these). A missing key renders as the raw key.
- **UI / forms**: follow [`.agent/FORM_STANDARD.md`](.agent/FORM_STANDARD.md) (Fluent UI v9, drawer-based forms, mobile-first).

## Recently done (2026-05-29 → 06-03)
- **FX correctness**: import now converts via the `rates` table (`resolveRate` queried
  it on all drivers + self-heals missing dates from ČNB single-currency endpoint);
  fixed corrupted 2022 USD history (ČNB dropped RUB → yearly-file column shift).
- **Market overview**: added S&P 500 universe, sector/industry/market-cap/P-E/exchange,
  CZK conversion, sector filter, and the **Kvalita** score. See `.agent/MARKET_OVERVIEW.md`.
- **P&L**: FX-difference + fees breakdown; case-insensitive `trans_type`.
- **i18n labels** live in `api/v3/translations/*.json` (see Critical Workflows).

## Current Focus / next
- **Populate the S&P 500 data**: run "Aktualizovat historii (max)" (now skips already-
  downloaded names and backfills dividend/market-cap/exchange in the same pass).
- **Consolidate import** onto the `api/v3/Import` OO layer; add Trading212 / Coinbase /
  eToro to v3 and retire `api/js/parsers`.
- **Port framework pieces from SHANON**: drawer forms, `SmartDataGrid`/`ActionBar`,
  settings dialog, keyboard shortcuts.
- Continue auditing legacy `/api/` files for hardcoded MySQL connections (use `get_pdo()`).
