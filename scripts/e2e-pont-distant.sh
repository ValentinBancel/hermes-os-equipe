#!/usr/bin/env bash
# Test de bout en bout du pont distant (L4) — aucun conteneur, aucune donnée réelle.
#
# Console réelle (php artisan serve, SQLite jetable, pilote « distant ») + pont Python réel sur un home Hermes
# de démonstration (pont/tests/demo-home) et un faux binaire `hermes` :
#   1. code d'appairage → `relier` → instance visible dans la console ;
#   2. `distant` pousse les relevés (santé, cron, détail des tâches…) ;
#   3. une action « cron.pause » mise en file par la console est récupérée par long-poll, exécutée, renvoyée ;
#   4. « Délier » → le pont reçoit 401 et s'arrête proprement.
#
#   scripts/e2e-pont-distant.sh            (port 18190 par défaut ; E2E_PORT pour changer)
set -euo pipefail

RACINE=$(cd "$(dirname "$0")/.." && pwd)
PORT=${E2E_PORT:-18190}
URL="http://127.0.0.1:$PORT"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/e2e-pont.XXXXXX")
SERVEUR="" PONT=""

nettoyer() {
    [ -n "$PONT" ] && kill "$PONT" 2>/dev/null || true
    [ -n "$SERVEUR" ] && kill "$SERVEUR" 2>/dev/null || true
    wait 2>/dev/null || true
    rm -rf "$TMP"
}
trap nettoyer EXIT

ok() { printf '  OK  %s\n' "$1"; }
ko() { printf '  KO  %s\n' "$1"; [ -f "$TMP/pont.log" ] && tail -20 "$TMP/pont.log"; [ -f "$TMP/serveur.log" ] && tail -20 "$TMP/serveur.log"; exit 1; }
attendre() { # secondes commande...
    local n=$1; shift
    for _ in $(seq "$n"); do "$@" && return 0; sleep 1; done
    return 1
}

# Console jetable : rien n'est lu du .env du dépôt (variables d'environnement prioritaires).
export APP_ENV=local APP_DEBUG=false APP_URL="$URL" APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
export DB_CONNECTION=sqlite DB_DATABASE="$TMP/console.sqlite" SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync
export HERMES_PONT_DRIVER=distant LOG_CHANNEL=stderr HERMES_OS_IP_ALLOWLIST=
touch "$DB_DATABASE"
php "$RACINE/artisan" migrate --force --no-interaction >/dev/null
console() { php "$RACINE/scripts/e2e/console.php" "$@"; }

# Plusieurs workers : le long-poll du pont occupe un worker jusqu'à 25 s.
PHP_CLI_SERVER_WORKERS=4 php "$RACINE/artisan" serve --host=127.0.0.1 --port="$PORT" --no-reload >"$TMP/serveur.log" 2>&1 &
SERVEUR=$!
attendre 20 curl -fs -o /dev/null "$URL/up" || ko "console injoignable sur $URL"
ok "console démarrée ($URL, pilote distant)"

# Home Hermes de démonstration + faux `hermes` (consigne ses appels).
python3 "$RACINE/pont/tests/demo_home.py" "$TMP/home" >/dev/null
python3 -c "import sys; sys.path.insert(0, '$RACINE/pont/tests'); import fixtures; fixtures.faux_hermes('$TMP/hermes', '$TMP/hermes.journal')"
PONT_ARGS=(--hermes-home "$TMP/home" --hermes-bin "$TMP/hermes" --sans-coffre)

# 1. Appairage
curl -fsS "$URL/pont/installer.sh" | grep -qF "CONSOLE='$URL'" || ko "installer.sh ne porte pas l'URL de la console"
ok "installer.sh servi avec l'URL de la console"
CODE=$(console code)
python3 "$RACINE/pont/hermes_os_pont.py" relier "$URL" --code "$CODE" --nom "e2e" --sans-systemd "${PONT_ARGS[@]}" >"$TMP/relier.log" 2>&1 \
    || { cat "$TMP/relier.log"; ko "relier"; }
console etat | grep -q '"nom":"e2e"' || ko "instance absente de la console après relier"
ok "relier : instance « e2e » visible dans la console"
python3 "$RACINE/pont/hermes_os_pont.py" relier "$URL" --code "$CODE" --sans-systemd "${PONT_ARGS[@]}" >/dev/null 2>&1 \
    && ko "un code déjà utilisé a été accepté"
ok "code à usage unique (second échange refusé)"

# 2. Relevés
python3 "$RACINE/pont/hermes_os_pont.py" distant --intervalle 2 -v "${PONT_ARGS[@]}" >"$TMP/pont.log" 2>&1 &
PONT=$!
releves_ok() { console etat | python3 -c 'import json,sys; e=json.load(sys.stdin); c=e["cles"]; sys.exit(0 if "/v1/sante" in c and "/v1/cron" in c and "/v1/cron/d0d0d0d0d0d1" in c and e["instance"]["latence_ms"] is not None else 1)'; }
attendre 30 releves_ok || { console etat; ko "relevés non reçus"; }
ok "relevés reçus : $(console etat | python3 -c 'import json,sys; print(json.load(sys.stdin)["releves"])') clés (santé, cron, détail des tâches)"

# 3. Action en file → long-poll → exécution → résultat
ID=$(console action d0d0d0d0d0d1)
fini() { [ "$(console suivre "$ID")" != attente ]; }
attendre 40 fini || ko "action non exécutée (40 s)"
RES=$(console suivre "$ID")
grep -q '"ok":true' <<<"$RES" || ko "résultat d'action en échec : $RES"
grep -q '"argv": \["cron", "pause", "d0d0d0d0d0d1"\]' "$TMP/hermes.journal" || ko "hermes cron pause non appelé"
ok "action cron.pause : récupérée par long-poll, exécutée par le pont, résultat lu par la console"

# 4. Délier → 401 → arrêt propre du pont
console delier >/dev/null
arrete() { ! kill -0 "$PONT" 2>/dev/null; }
attendre 40 arrete || ko "le pont tourne encore après « Délier »"
wait "$PONT" 2>/dev/null; CODE_SORTIE=$?; PONT=""
grep -qi "délié de la console" "$TMP/pont.log" || ko "le pont ne signale pas la révocation"
ok "délier : le pont reçoit 401 et s'arrête (code de sortie $CODE_SORTIE)"

echo "E2E pont distant : OK"
