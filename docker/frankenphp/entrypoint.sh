#!/bin/sh
# Démarrage de l'image de production : prépare /app/storage (volume), migre la base SQLite,
# met en cache la configuration, puis lance FrankenPHP. Aucune donnée du home Hermes.
set -eu
cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "[hermes-os] APP_KEY manquante : la définir dans l'environnement (php artisan key:generate --show)." >&2
    exit 1
fi

mkdir -p storage/data storage/app/private storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

php artisan migrate --force --no-interaction
php artisan optimize --no-interaction

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
