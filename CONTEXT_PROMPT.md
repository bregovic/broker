# Antigravity Context Prompt - Broker Project

Always refer to this document when working on the Broker project to ensure continuity and best practices.

## Project Summary
Modernized investment broker application moved from legacy Wedos/MySQL Hosting to Railway/PostgreSQL/Docker.

## Architecture Guidelines
- **API Base**: All backend logic is in the `/api/` directory.
- **Frontend**: React/Vite application in the `/frontend/` directory.
- **Database**:
  - Use `api/get_pdo()` from `api/config.php` for ALL database connections.
  - Prioritize `DATABASE_URL` environment variable (PostgreSQL).
  - Use `ON CONFLICT` for Postgres or ensure cross-db compatibility.
- **Deployment**: Automatic via GitHub Actions -> Railway (Dockerfile-based).

## Critical Workflows
- **New Feature/Fix**:
  1. Record change in `RELEASE_NOTES.md`.
  2. If table changes are needed, update `api/init_broker.php`.
  3. Ensure all paths use the `/api/` prefix in the frontend.
- **Labels**:
  - UI labels are managed via `api/api-translations.php` and stored in the `translations` table.
  - For new labels, add them to the setup script `api/init_broker.php`.

## Current Focus
- Auditing legacy PHP files in `/api/` to remove hardcoded MySQL connections.
- Ensuring form displays and labels are correctly retrieved.
