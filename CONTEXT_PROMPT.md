# Antigravity Context Prompt - Broker Project

Always refer to this document when working on the Broker project to ensure continuity and best practices.

## Project Summary
Modernized investment broker application moved from legacy Wedos/MySQL Hosting to Railway/PostgreSQL/Docker.

## Architecture Guidelines
- **API Base**: All backend logic is in the `/api/` directory.
- **Frontend**: React/Vite application in the `/frontend/` directory (Fluent UI v9 components).
- **Database**:
  - Use `api/get_pdo()` from `api/config.php` for ALL database connections.
  - Prioritize `DATABASE_URL` environment variable (PostgreSQL).
  - Use `ON CONFLICT` for Postgres or ensure cross-db compatibility.
- **Deployment**: Automatic via GitHub Actions -> Railway (Dockerfile-based).

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

## Frontend Parsers
Located in `frontend/src/utils/parsers/`:
- **RevolutParser** – Stocks PDF/CSV (delegates crypto/commodity)
- **RevolutCryptoParser** – Crypto PDF/CSV
- **RevolutCommodityParser** – Commodity (XAU, XAG) PDF/CSV
- **IbkrParser** – Interactive Brokers PDF
- **Trading212Parser** – Trading 212 CSV/Excel
- **FioParser** – Fio Banka PDF
- **CoinbaseParser** – Coinbase CSV

## Critical Workflows
- **New Feature/Fix**:
  1. Record change in `RELEASE_NOTES.md`.
  2. If table changes are needed, update `api/init_broker.php`.
  3. Ensure all paths use the `/api/` prefix in the frontend.
- **Labels**:
  - UI labels are managed via `api/api-translations.php` and stored in the `translations` table.
  - For new labels, add them to the setup script `api/init_broker.php`.

## Current Focus
- Ensuring dividend calculations work correctly across all importers.
- Respecting user's `base_currency` setting in all financial displays.
- Auditing legacy PHP files in `/api/` to remove hardcoded MySQL connections.
