#!/usr/bin/env bash
# Mesure du panneau « Hermes relié » (L4, design-critic M5 et m9) dans un vrai Chromium — aucun conteneur, aucune donnée réelle.
#
# Console jetable (php artisan serve, SQLite temporaire, pilote « distant ») + Playwright pour Python (hors projet :
# `python3 -c "import playwright"` doit réussir, navigateur installé par `python3 -m playwright install chromium`).
# 11 états du panneau mesurés à 1440 et 390 px (voir scripts/e2e/mesure_panneau_relie.py) ; sortie chiffrée, code 1 si un KO.
#
#   scripts/mesure-panneau-relie.sh                 (port 18191 ; MESURE_PORT pour changer)
#   CAPTURES=/chemin scripts/mesure-panneau-relie.sh   → L4-code-panneau-{1440,390}.png (état « code affiché »)
#
# Prérequis : assets construits (npm run build).
set -euo pipefail

RACINE=$(cd "$(dirname "$0")/.." && pwd)
PORT=${MESURE_PORT:-18191}
URL="http://127.0.0.1:$PORT"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/mesure-panneau.XXXXXX")
SERVEUR=""

nettoyer() {
    [ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null || true
    wait 2>/dev/null || true
    rm -rf "$TMP"
}
trap nettoyer EXIT

[ -f "$RACINE/public/build/manifest.json" ] || { echo "Assets absents : lancer npm run build." >&2; exit 1; }

# Console jetable : rien n'est lu du .env du dépôt. Sessions en base et non chiffrées (pour « vieillir » le code TOTP),
# cookie non sécurisé (HTTP local) ; fuseau d'affichage Europe/Paris, navigateur en UTC.
export APP_ENV=local APP_DEBUG=false APP_URL="$URL" APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export DB_CONNECTION=sqlite DB_DATABASE="$TMP/console.sqlite" CACHE_STORE=array QUEUE_CONNECTION=sync
export SESSION_DRIVER=database SESSION_ENCRYPT=false SESSION_SECURE_COOKIE=false
export HERMES_PONT_DRIVER=distant HERMES_OS_FUSEAU=Europe/Paris LOG_CHANNEL=stderr HERMES_OS_IP_ALLOWLIST=
touch "$DB_DATABASE"
php "$RACINE/artisan" migrate --force --no-interaction >/dev/null

export EMAIL="mesure@exemple.invalid" MOT_DE_PASSE="mesure-$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
export OUTIL="php $RACINE/scripts/e2e/mesure.php" URL
SECRET=$($OUTIL compte "$EMAIL" "$MOT_DE_PASSE")
export SECRET

PHP_CLI_SERVER_WORKERS=4 php "$RACINE/artisan" serve --host=127.0.0.1 --port="$PORT" --no-reload >"$TMP/serveur.log" 2>&1 &
SERVEUR=$!
for _ in $(seq 20); do curl -fs -o /dev/null "$URL/up" && break; sleep 1; done
curl -fs -o /dev/null "$URL/up" || { tail -20 "$TMP/serveur.log"; echo "console injoignable sur $URL" >&2; exit 1; }
echo "Console jetable : $URL (pilote distant, fuseau Europe/Paris, navigateur UTC)"

[ -n "${CAPTURES:-}" ] && mkdir -p "$CAPTURES"
python3 "$RACINE/scripts/e2e/mesure_panneau_relie.py" || { tail -20 "$TMP/serveur.log"; exit 1; }
