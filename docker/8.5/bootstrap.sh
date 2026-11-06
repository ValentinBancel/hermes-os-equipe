#!/usr/bin/env bash
# Démarrage Sail : rend `sail up` (= `docker compose up`) suffisant sur un clone neuf, comme
# après un changement de branche. Idempotent : chaque étape ne s'exécute que si nécessaire.
# Aucun accès au home Hermes : le pont tourne en mode `demo` (HERMES_PONT_DRIVER).
set -euo pipefail
cd /var/www/html

log() { echo "[bootstrap] $*"; }
# Empreinte (contenu) d'un ensemble de fichiers, pour savoir s'ils ont changé depuis la dernière fois.
empreinte() { find "$@" -type f 2>/dev/null | LC_ALL=C sort | xargs -r sha1sum | sha1sum | cut -d' ' -f1; }

# Un public/hot laissé par un `npm run dev` interrompu ferait charger les assets d'un Vite arrêté.
rm -f public/hot

if [ ! -f .env ]; then
    log ".env absent : copie de .env.example"
    cp .env.example .env
fi

lock_php=$(empreinte composer.lock)
if [ ! -f vendor/autoload.php ] || [ "$(cat vendor/.lock-hash 2>/dev/null)" != "$lock_php" ]; then
    log "composer install"
    composer install --no-interaction --prefer-dist --no-progress
    echo "$lock_php" > vendor/.lock-hash
fi

if ! grep -qE '^APP_KEY=.+' .env; then
    log "génération de APP_KEY"
    php artisan key:generate --force --no-interaction
fi

if grep -qE '^DB_CONNECTION=sqlite' .env; then
    db=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- | tr -d '"' || true)
    db=${db:-database/database.sqlite}
    [ -f "$db" ] || { log "création de $db"; touch "$db"; }
fi

log "migrations"
php artisan migrate --force --no-interaction

lock_js=$(empreinte package-lock.json)
if [ ! -d node_modules ] || [ "$(cat node_modules/.lock-hash 2>/dev/null)" != "$lock_js" ]; then
    log "npm ci"
    npm ci --no-audit --no-fund
    echo "$lock_js" > node_modules/.lock-hash
fi

# Assets reconstruits si absents ou si les sources ont changé (sinon, après un `git switch`,
# on servirait le CSS de l'ancienne branche). Rechargement à chaud : `sail npm run dev`.
src=$(empreinte resources vite.config.js package-lock.json)
if [ ! -f public/build/manifest.json ] || [ "$(cat public/build/.source-hash 2>/dev/null)" != "$src" ]; then
    log "npm run build"
    npm run build
    echo "$src" > public/build/.source-hash
fi

php artisan optimize:clear --no-interaction >/dev/null 2>&1 || true
log "prêt"
