# Návod na nasazení (Deployment)

Tato složka obsahuje soubory a návody pro nasazení aplikace OtaMat (Backend na Railway, Frontend na Wedos FTP).

## 1. Backend (Railway)
- Viz soubor `RAILWAY_SETUP.md` v této složce.
- Klíčový je soubor `.env` (viz `backend_env_example.txt`).

## 2. Frontend (FTP - Wedos)
Frontend je statická Next.js aplikace, která se "vybuildí" a nahraje na FTP.

### Postup:
1.  Ujistěte se, že máte nainstalované závislosti: `npm install`
2.  V souboru `src/utils/config.ts` zkontrolujte URL backendu.
3.  V souboru `ftp_deploy_script.js` (kopie `upload_ftp.js`) upravte přihlašovací údaje k FTP:
    - `host`: "vas.server.wedos.net"
    - `user`: "w..."
    - `password`: "..."
    - `remoteRoot`: "www/vasi-slozka" (cílová složka na FTP)
4.  Spusťte build a upload:
    ```bash
    npm run build
    node ftp_deploy_script.js
    ```

### Důležité:
- Skript `ftp_deploy_script.js` vyžaduje balíček `basic-ftp`. Pokud ho nemáte v novém projektu, nainstalujte ho:
  `npm install basic-ftp --save-dev`
