# Modernizované Nasazení (Deployment) - Broker / Investyx 2.0

## Struktura projektu
- `/frontend`: React frontend (Vite)
- `/api`: PHP backend (API v3)
- `Dockerfile`: Konfigurace pro Railway (One-step build)
- `nginx.conf`: Konfigurace webového serveru

## Nasazení na Railway
Projekt je nastaven pro automatické nasazení přes Docker. 
Railway najde `Dockerfile` v kořenu a provede následující:
1. Sestaví frontend (React) ve fázi `frontend-builder`.
2. Sestaví produkční obraz s `php-nginx`.
3. Zkopíruje PHP soubory z `/api` do `/var/www/html/`.
4. Zkopíruje zkompilovaný frontend do `/var/www/html/public`.
5. Spustí Nginx na portu 8080.

### Důležité cesty v aplikaci
- **Frontend** běží na kořenové URL `/`.
- **Backend (API)** je dostupný pod prefixem `/api/` (např. `/api/v3/api-import.php`).
- Nginx toto mapování zajišťuje automaticky.

## Lokální spuštění přes Docker
Pokud máš nainstalovaný Docker, můžeš si aplikaci spustit lokálně:
```bash
docker build -t broker-local .
docker run -p 8080:8080 broker-local
```
Poté bude aplikace dostupná na `http://localhost:8080`.

## GitHub Actions (FTP Backup)
Soubor `.github/workflows/deploy.yml` stále obsahuje konfiguraci pro záložní nasazení na FTP (Wedos), pokud by bylo potřeba. Je nastaven tak, aby používal novou strukturu složek.
