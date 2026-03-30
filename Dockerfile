# --- STAGE 1: Build Frontend (React) ---
FROM node:20-alpine as frontend-builder
WORKDIR /app
COPY broker-client/package*.json ./
RUN npm ci
COPY broker-client/ ./
RUN npm run build

# --- STAGE 2: Production Server (PHP + Nginx) ---
FROM trafex/php-nginx:3.5.0
USER root

# Instalace PostgreSQL ovladačů pro PHP
RUN apk add --no-cache \
    php83-pdo_pgsql \
    php83-pgsql

# Kopírujeme Nginx konfiguraci
COPY nginx.conf /etc/nginx/nginx.conf

# Setup Backendu
WORKDIR /var/www/html
COPY broker\ 2.0/ ./


# Kopírujeme zkompilovaný Frontend z prvního kroku
COPY --from=frontend-builder /app/dist/. ./public

# Oprávnění
RUN chown -R nobody.nobody /var/www/html
USER nobody

# Port 8080 je standardem pro Railway
EXPOSE 8080
