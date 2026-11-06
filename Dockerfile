# Image de production hermes-os (ADR 0006) : Node (Vite) → Composer --no-dev → FrankenPHP.
# Build : docker build -t hermes-os .
# Run   : docker run -p 8080:8080 -e APP_KEY=base64:… -e APP_URL=https://console.example.test \
#             -v hermes-os-data:/app/storage --read-only --tmpfs /tmp hermes-os
# Écoute HTTP sur :8080 (TLS chez le reverse proxy), utilisateur uid 1000, SQLite dans /app/storage/data.

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.2-php8.5.12

############################
# 1. Assets front (Vite + Tailwind)
############################
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
# app.css importe le design system (jetons, composants, ornements) et scanne app/Interface (@source : classes de Libelles).
COPY design-system ./design-system
COPY app/Interface ./app/Interface
RUN npm run build

############################
# 2. Dépendances PHP (sans dev), avec le même PHP que l'image finale
############################
FROM ${FRANKENPHP_IMAGE} AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

############################
# 3. Image finale
############################
FROM ${FRANKENPHP_IMAGE}

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/app/storage/data/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    HERMES_PONT_DRIVER=demo \
    APP_CONFIG_CACHE=/app/storage/framework/cache/config.php \
    APP_ROUTES_CACHE=/app/storage/framework/cache/routes.php \
    APP_EVENTS_CACHE=/app/storage/framework/cache/events.php \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && groupadd --gid 1000 app \
    && useradd --uid 1000 --gid 1000 --no-create-home --shell /usr/sbin/nologin app

COPY docker/frankenphp/php.ini $PHP_INI_DIR/conf.d/zz-hermes-os.ini
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=0755 docker/frankenphp/entrypoint.sh /usr/local/bin/hermes-os-entrypoint

WORKDIR /app
COPY --chown=1000:1000 --from=vendor /app /app
COPY --chown=1000:1000 --from=assets /app/public/build /app/public/build

# Caches de paquets générés au build (bootstrap/cache reste en lecture seule à l'exécution).
RUN rm -rf .env tests docker scripts docs node_modules \
    && mkdir -p storage/data /run/hermes-os \
    && chown 1000:1000 /run/hermes-os \
    && chown -R 1000:1000 storage bootstrap/cache \
    && su -s /bin/sh app -c "php artisan package:discover --ansi"

USER 1000:1000
VOLUME ["/app/storage"]
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["hermes-os-entrypoint"]
