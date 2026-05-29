---
description: Standard operating procedure for making a change in Investyx
---

# SOP — How to make a change safely

Read [`../CONTEXT_PROMPT.md`](../CONTEXT_PROMPT.md) first. This is the step-by-step.

## 1. Before you start
- Confirm you are in `hollyhop/broker` (repo `bregovic/broker.git`), branch `main`, clean working tree.
- Understand whether the change touches **frontend** (`frontend/src`), **backend** (`api/`), or **schema**.

## 2. Backend (PHP)
- Always connect via `get_pdo()` from `api/config.php`. Never hardcode DB credentials.
- Write **cross-driver SQL** (PostgreSQL on Railway, MySQL locally). When syntax differs,
  branch on `$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)`.
- Reading a ticker from `transactions`? Use `COALESCE(ticker, id)`.
- Filtering dividends/tax? Use `trans_type IN ('Dividend','Withholding','Tax')`.
- Wrap optional/legacy column access in try/catch so missing columns don't 500 the endpoint.

## 3. Frontend (React + Fluent UI v9)
- Follow [`FORM_STANDARD.md`](FORM_STANDARD.md) (drawer forms, mobile-first, localized text).
- Call the backend with the `/api/` prefix.
- Keep types in sync with the API response shape.

## 4. Schema changes
- Add new tables/columns to `api/init_broker.php` so fresh environments bootstrap.
- Keep it cross-driver. Prefer additive changes; `db_repair.php` can self-heal missing columns.

## 5. Imports (adding/fixing a broker)
- Extend the **`api/v3/Import`** OO layer: a new parser class under `Csv/` or `Pdf/`
  extending the right abstract base, emitting `TransactionDTO`s, registered in `ImportManager`.
- Avoid adding new logic to `api/js/parsers` (legacy, being retired).

## 6. Verify
- `cd frontend && npm run build` — **must pass** (`tsc -b && vite build`).
- Sanity-check the affected endpoint/page locally if possible.

## 7. Ship
- Update `RELEASE_NOTES.md`.
- Commit with **Conventional Commits** (`feat:`, `fix:`, `refactor:`, `docs:`, `chore:`).
- Push `main` → Railway auto-deploys. Watch the Railway dashboard for a green build.

## 8. Don't commit
- `scratch/`, `api/env.local.php`, `frontend/dist/`, `node_modules/`, secrets, debug dumps.
