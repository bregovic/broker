# --- STAGE 1: Build Frontend (React) ---
FROM node:20-alpine as frontend-builder
WORKDIR /app
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# --- STAGE 2: Production Server (PHP + Nginx) ---
FROM trafex/php-nginx:3.5.0
USER root

# Instalace DB ovladačů pro PHP (MySQL a PostgreSQL)
RUN apk add --no-cache \
    php83-pdo_mysql \
    php83-mysqlnd \
    php83-pdo_pgsql \
    php83-pgsql

# Kopírujeme Nginx konfiguraci
COPY nginx.conf /etc/nginx/nginx.conf

# Setup Backendu
WORKDIR /var/www/html
COPY api/ ./

# Kopírujeme zkompilovaný Frontend z prvního kroku
COPY --from=frontend-builder /app/dist/. ./public

# Oprávnění
RUN chown -R nobody.nobody /var/www/html
USER nobody

# Port 8080 je standardem pro Railway
EXPOSE 8080
