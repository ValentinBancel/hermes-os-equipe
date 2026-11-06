#!/usr/bin/env bash
# Mesure des cibles tactiles (L6 tour 2, design-critic M1) dans un vrai Chromium — aucun conteneur, aucune donnée réelle.
#
# Console jetable (php artisan serve, SQLite temporaire, pilote « demo ») + Playwright pour Python (hors projet :
# `python3 -c "import playwright"` doit réussir, navigateur installé par `python3 -m playwright install chromium`).
# À 390 px, aucun élément cliquable de la barre et de `main` ne doit faire moins de 40 px (var(--espace-10)) de haut,
# sur toutes les pages et dans les états ouverts (confirmation et formulaire du Coffre). Voir scripts/e2e/mesure_cibles.py.
#
#   scripts/mesure-cibles.sh                          (port 18192 ; MESURE_PORT pour changer)
#   CAPTURES=/chemin scripts/mesure-cibles.sh         → captures 1440 et 390 des pages et états mesurés
#   HERMES_DEMO_ETAT=pont_injoignable scripts/mesure-cibles.sh   → même mesure, interface figée (pont hors ligne)
#
# Prérequis : assets construits (npm run build). Sortie chiffrée, code 1 si un KO.
set -euo pipefail

RACINE=$(cd "$(dirname "$0")/.." && pwd)
PORT=${MESURE_PORT:-18192}
URL="http://127.0.0.1:$PORT"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/mesure-cibles.XXXXXX")
SERVEUR=""

nettoyer() {
    [ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null || true
    wait 2>/dev/null || true
    rm -rf "$TMP"
}
trap nettoyer EXIT

[ -f "$RACINE/public/build/manifest.json" ] || { echo "Assets absents : lancer npm run build." >&2; exit 1; }

# Console jetable : rien n'est lu du .env du dépôt.
export APP_ENV=local APP_DEBUG=false APP_URL="$URL" APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export DB_CONNECTION=sqlite DB_DATABASE="$TMP/console.sqlite" CACHE_STORE=array QUEUE_CONNECTION=sync
export SESSION_DRIVER=database SESSION_ENCRYPT=false SESSION_SECURE_COOKIE=false
export HERMES_PONT_DRIVER=demo HERMES_DEMO_ETAT="${HERMES_DEMO_ETAT:-nominal}" HERMES_OS_FUSEAU=Europe/Paris
export LOG_CHANNEL=stderr HERMES_OS_IP_ALLOWLIST= HERMES_OS_MESSAGERIE=true
touch "$DB_DATABASE"
php "$RACINE/artisan" migrate --force --no-interaction >/dev/null

export EMAIL="mesure@exemple.invalid" MOT_DE_PASSE="mesure-$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
export EMAIL_NEUF="neuf@exemple.invalid"
export OUTIL="php $RACINE/scripts/e2e/mesure.php" URL
SECRET=$($OUTIL compte "$EMAIL" "$MOT_DE_PASSE")
export SECRET
$OUTIL nouveau "$EMAIL_NEUF" "$MOT_DE_PASSE" >/dev/null

PHP_CLI_SERVER_WORKERS=4 php "$RACINE/artisan" serve --host=127.0.0.1 --port="$PORT" --no-reload >"$TMP/serveur.log" 2>&1 &
SERVEUR=$!
for _ in $(seq 20); do curl -fs -o /dev/null "$URL/up" && break; sleep 1; done
curl -fs -o /dev/null "$URL/up" || { tail -20 "$TMP/serveur.log"; echo "console injoignable sur $URL" >&2; exit 1; }
echo "Console jetable : $URL (pilote demo, état $HERMES_DEMO_ETAT)"

[ -n "${CAPTURES:-}" ] && mkdir -p "$CAPTURES"
python3 "$RACINE/scripts/e2e/mesure_cibles.py" || { tail -20 "$TMP/serveur.log"; exit 1; }
