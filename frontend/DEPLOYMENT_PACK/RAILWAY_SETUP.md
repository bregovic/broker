# Nasazení Backendu na Railway

Backend běží na Node.js (NestJS) a používá PostgreSQL databázi.

## Postup:

1.  **Vytvořte projekt na Railway:**
    - Jděte na [railway.app](https://railway.app/).
    - Klikněte na "New Project" -> "Deploy from GitHub repo".
    - Vyberte repozitář s vaším projektem.

2.  **Přidejte Databázi:**
    - V projektu na Railway klikněte pravým tlačítkem (nebo "New") -> Database -> PostgreSQL.
    - Railway automaticky vytvoří databázi.

3.  **Propojení Proměnných (Variables):**
    - Klikněte na vaši Backend službu (GitHub repo).
    - Jděte do záložky "Variables".
    - Přidejte proměnnou `DATABASE_URL`.
    - Hodnotu získáte z PostgreSQL služby (záložka "Connect" -> sekce "Postgres Connection URL").
        - *Tip: Často můžete použít referenci `${{PostgreSQL.DATABASE_URL}}`.*
    - Nastavte `PORT` na `3000` (nebo jiný, ale NestJS defaultně poslouchá na portu z env nebo 3000).

4.  **Generování Prisma Klienta (Build Command):**
    - Aby backend věděl o databázi, musí se při startu vygenerovat Prisma.
    - V záložce "Settings" -> "Build" -> "Build Command" nastavte:
      ```bash
      npm install && npx prisma generate && npm run build
      ```
      *(Pozor: Pokud je backend v podadresáři, musíte nastavit "Root Directory" na `/backend`).*

5.  **Spouštěcí příkaz (Start Command):**
    - V "Settings" -> "Deploy" -> "Start Command":
      ```bash
      npm run start:prod
      ```

6.  **Migrace Databáze:**
    - Při prvním nasazení (nebo změně schématu) je vhodné spustit migraci. Můžete to udělat buď lokálně proti produkční DB (změnou .env) nebo v rámci Deploy commandu (což je ale riskantní pro produkci).
    - Doporučuji lokálně:
      1. Zjistěte `DATABASE_URL` z Railway.
      2. Upravte lokální `.env` v backendu.
      3. Spusťte `npx prisma migrate deploy`.

## Struktura:
Railway automaticky detekuje, že jde o Node.js aplikaci díky `package.json`.
Důležité je mít správně nastavený `Root Directory` v nastavení služby, pokud není `package.json` přímo v kořenu repozitáře (u nás je ve složce `backend`).
