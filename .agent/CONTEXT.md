# 💼 Investyx V3: Projektový Manifest & Best Practices

Tento soubor definuje moderní architekturu projektu **Broker 3.0** (Investyx) a pravidla pro jeho rozvoj.

## 🚀 Technologie (Stack)
- **Frontend**: React 19, TypeScript, Vite, Fluent UI v9.
- **Backend (V3)**: PHP 8.3+, PostgreSQL (Postgres) na Railway.
- **Infrastruktura**: Docker (multi-stage), Nginx (trafex/php-nginx).
- **Deployment**: Automatický CI/CD z GitHubu přímo na Railway přes `Dockerfile`.

## 🏗️ Architektura & Best Practices

### 1. Importní Engine (OOP Hierarchy)
Místo `if/else` bloků používáme **Factory + Strategy pattern**:
- **`AbstractParser`**: Společný předek definující `canParse()` a `parse()`.
- **Formátové parsery**: `AbstractCsvParser`, `AbstractPdfParser` (pro sdílenou logiku daného souboru).
- **Konkrétní potomci**: `FioCsvParser`, `RevolutPdfParser`. Každý broker má svou třídu.
- **`TransactionDTO`**: Vše se unifikuje do jednoho objektu před uložením do Postgresu.

### 2. Formáty & Rozhraní
- **Metadata**: Pro extra data z výpisů používáme v Postgresu sloupce typu `JSONB`.
- **SmartDataGrid**: Pro všechny tabulky s portfoliem a transakcemi používáme komponentu ze šablony Shanon.

### 3. Labely & Lokalizace (Labels System)
- Překlady a labely jsou v `/v3/translations/*.json`.
- UI nesmí obsahovat texty natvrdo. Vše se tahá přes `TranslationProvider`.

## 🔗 Nasazení (Railway)
- **Public Domain**: `investyx.up.railway.app`
- **Internal Database**: Přístupná přes `DATABASE_URL` zenv proměnných.
- **Mapování**: Nginx přesměrovává `/api/*` na PHP soubory a zbytek na React dist.

## 🛠️ Pravidla pro vývoj:
- **Nové funkce?** Vždy piš do složky `v3/` (Backend) a používej nové API.
- **Nové tabulky?** Přidávej je do `install-db.php`, aby byly migrace automatické.
- **Nové importy?** Vytvoř nového potomka ve složce `v3/Import/`.

Tento soubor by měl sloužit jako základ pro každé AI, které na projektu pracuje. 🧠✨
