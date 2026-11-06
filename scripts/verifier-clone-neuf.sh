#!/usr/bin/env bash
# Garde-fou « clone neuf » de hermes-os : à lancer avant toute PR / livraison.
#   1. clone neuf + `docker compose up -d` (= sail up) seul → pages attendues, tests Pest sous PHP 8.5,
#      création de 2 comptes en CLI (L3 multi-comptes) : le premier → enrôlement TOTP forcé, le second
#      (mot de passe provisoire) → changement du mot de passe forcé ;
#   2. build du Dockerfile de production + run (FS en lecture seule) → healthcheck « healthy »,
#      mêmes pages, même parcours de connexion ;
#   3. dans les deux cas, « Relier un Hermes » de bout en bout (L5, scripts/e2e/relier-console.sh) : pilote
#      `distant`, connexion → enrôlement TOTP → code d'appairage dans Compte → `hermes_os_pont.py relier
#      http://…:180xx --code … --sans-systemd` sur un home Hermes de démonstration → boucle `distant` → Compte
#      affiche l'instance « En ligne » et l'accueil les données de démonstration. Le pont tourne sur l'hôte
#      (python3 ≥ 3.11) avec un HOME jetable.
# Isolation : projets compose / conteneurs / images / volumes préfixés hermes-os-verif-*, ports 18080,
# 15173 et 18090 liés à 127.0.0.1, tout dans un dossier temporaire sous /data. Nettoyage complet
# (down -v --rmi local) à la sortie, même en cas d'échec. Aucun autre conteneur n'est touché.
# Aucun secret affiché : APP_KEY et mot de passe de test sont générés et jamais écrits dans la sortie.
# Usage : scripts/verifier-clone-neuf.sh [branche]   (défaut : branche courante, commits uniquement)
set -uo pipefail

# Une seule vérification à la fois (qa m2) : deux runs partagent les mêmes ports, noms de conteneurs et volume.
# Chemin fixe (pas TMPDIR, propre à chaque agent) sur tmpfs : /run/lock, sinon /tmp.
VERROU_DIR=/run/lock; [ -w "$VERROU_DIR" ] || VERROU_DIR=/tmp
VERROU=${VERIF_VERROU:-$VERROU_DIR/hermes-os-verif.lock}
exec 9>"$VERROU"
if ! flock -n 9; then
  echo "Autre vérification en cours (verrou $VERROU) : réessayer quand elle sera terminée." >&2
  exit 2
fi

REPO=$(cd "$(dirname "$0")/.." && pwd)
BR=${1:-$(git -C "$REPO" rev-parse --abbrev-ref HEAD)}
BASE_TMP=${VERIF_TMP:-${TMPDIR:-/tmp}}
mkdir -p "$BASE_TMP"
T=$(mktemp -d "$BASE_TMP/hermes-os-verif.XXXXXX")
SAIL_PROJET=hermes-os-verif-sail
PROD_NOM=hermes-os-verif-prod
PROD_IMAGE=hermes-os-verif:local
PROD_VOLUME=hermes-os-verif-data
SAIL_PORT=18080
VITE_PORT=15173
PROD_PORT=18090
EMAIL=verif@example.test
EMAIL2=verif2@example.test
MDP="verif-$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
FAIL=0

nettoyer() {
    (cd "$T/sail" 2>/dev/null && docker compose -p "$SAIL_PROJET" down -v --rmi local --remove-orphans >/dev/null 2>&1)
    docker rm -f "$PROD_NOM" >/dev/null 2>&1
    docker volume rm "$PROD_VOLUME" >/dev/null 2>&1
    docker rmi "$PROD_IMAGE" >/dev/null 2>&1
    # Fichiers créés par le conteneur Sail (uid courant) : suppression sans sudo.
    rm -rf "$T"
}
trap nettoyer EXIT

ko() { echo "  KO $*"; FAIL=1; ERREURS=$((ERREURS + 1)); }
ERREURS=0
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
attendre200() { for _ in $(seq 1 "${2:-120}"); do [ "$(code "$1")" = 200 ] && return 0; sleep 5; done; return 1; }

# Pages attendues : 200 publiques, 302 vers /connexion pour les pages protégées, 404 pour Fortify désactivé.
verifier_pages() { # base
    local base=$1 u c; ERREURS=0
    for u in /connexion /up /robots.txt /build/manifest.json /favicon.svg /apple-touch-icon.png /pont/installer.sh /pont/fichiers/hermes_os_distant.py; do
        c=$(code "$base$u"); [ "$c" = 200 ] || ko "$u -> $c (200 attendu)"
    done
    for u in / /securite/totp /securite/codes /planifiees /consommation /consommation/sessions /poles /compte /compte/journal; do
        c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$base$u")
        [ "$c" = "302 $base/connexion" ] || ko "$u -> $c (302 vers /connexion attendu)"
    done
    for u in /register /forgot-password /login /two-factor-challenge /user/two-factor-recovery-codes /passkeys/login/options /kanban /kanban/dev /terminal /missions /nexiste-pas; do
        c=$(code "$base$u"); [ "$c" = 404 ] || ko "$u -> $c (404 attendu)"
    done
    # Pont distant (L4) : API hors session/CSRF — code faux → 422, requête non signée → 401, installeur avec l'URL.
    c=$(code -X POST -H 'Content-Type: application/json' -d '{"code":"ZZZZZZZZ"}' "$base/pont/v1/appairage"); [ "$c" = 422 ] || ko "POST /pont/v1/appairage -> $c (422 attendu)"
    c=$(code "$base/pont/v1/actions"); [ "$c" = 401 ] || ko "GET /pont/v1/actions non signé -> $c (401 attendu)"
    grep -q "^CONSOLE='http" <<<"$(curl -s "$base/pont/installer.sh")" || ko "installer.sh sans URL de console"
    local h; h=$(curl -sI "$base/connexion")
    grep -qi "^content-security-policy: default-src 'self'; script-src 'self' 'nonce-" <<<"$h" || ko "CSP avec nonce absente"
    grep -qi '^x-robots-tag: noindex, nofollow' <<<"$h" || ko "X-Robots-Tag absent"
    grep -qi '^referrer-policy: no-referrer' <<<"$h" || ko "Referrer-Policy absent"
    grep -qi '^x-content-type-options: nosniff' <<<"$h" || ko "nosniff absent"
    grep -qi '^strict-transport-security: max-age=31536000' <<<"$h" || ko "HSTS absent"
    # Corps lu en entier avant grep -q : avec pipefail, « curl | grep -q » échoue (SIGPIPE) dès que la page
    # dépasse un tampon (sprite SVG en tête des pages depuis le lot 07).
    grep -qx 'Disallow: /' <<<"$(curl -s "$base/robots.txt")" || ko "robots.txt sans Disallow: /"
    # ADR 0004 (decisions.md § v2, M9 amendé) : écrans publics neutres, aucune mention de « hermes » dans le HTML.
    local html; html=$(curl -s "$base/connexion")
    grep -q '<title>Connexion</title>' <<<"$html" || ko "titre de connexion neutre (ADR 0004)"
    grep -qi 'hermes' <<<"$html" && ko "« hermes » présent dans le HTML de /connexion (ADR 0004)"
    grep -q '<link rel="icon" href="/favicon.svg" type="image/svg+xml">' <<<"$html" || ko "favicon SVG non déclaré (D5)"
    [ "$ERREURS" = 0 ] && echo "  pages (200 / 302 / 404 attendus), en-têtes de sécurité, /connexion neutre (0 « hermes ») OK"
}

# Q1 : nosniff et X-Robots-Tag aussi sur les fichiers statiques (servis par Caddy, sans PHP). Image de production seulement.
verifier_statiques() { # base
    local base=$1 u h; ERREURS=0
    for u in /robots.txt /build/manifest.json /favicon.svg /apple-touch-icon.png; do
        h=$(curl -sI "$base$u")
        grep -qi '^x-content-type-options: nosniff' <<<"$h" || ko "$u sans nosniff"
        grep -qi '^x-robots-tag: noindex, nofollow' <<<"$h" || ko "$u sans X-Robots-Tag"
    done
    h=$(curl -sI "$base/connexion")
    [ "$(grep -ci '^x-content-type-options:' <<<"$h")" = 1 ] || ko "/connexion : X-Content-Type-Options en double"
    [ "$ERREURS" = 0 ] && echo "  statiques : nosniff + X-Robots-Tag OK (en-têtes PHP non dupliqués)"
}

# Connexion réelle avec un compte créé en CLI : mot de passe → page forcée (enrôlement TOTP ou nouveau mot de passe).
verifier_connexion() { # base email mdp chemin_force libelle
    local base=$1 email=$2 mdp=$3 force=$4 libelle=$5 jar="$T/cookies-$RANDOM" jeton c; ERREURS=0
    jeton=$(curl -s -c "$jar" -b "$jar" "$base/connexion" | grep -o 'name="_token" value="[^"]*"' | cut -d'"' -f4)
    [ -n "$jeton" ] || { ko "jeton CSRF introuvable"; return; }
    c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$jar" -b "$jar" \
        --data-urlencode "_token=$jeton" --data-urlencode "email=$email" --data-urlencode "password=$mdp" "$base/connexion")
    [ "$c" = "302 $base/" ] || { ko "POST /connexion ($email) -> $c"; return; }
    c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -c "$jar" -b "$jar" "$base/")
    [ "$c" = "302 $base$force" ] || ko "accueil avant $libelle ($email) -> $c ($force attendu)"
    if [ "$force" = /securite/totp ]; then
        grep -q 'aria-label="QR code d.enrôlement"><svg' <<<"$(curl -s -c "$jar" -b "$jar" "$base/securite/totp")" \
            || ko "écran d'enrôlement sans QR code"
    else
        c=$(curl -s -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" "$base$force")
        [ "$c" = 200 ] || ko "$force -> $c"
    fi
    c=$(curl -s -o /dev/null -w '%{http_code}' -c "$jar" -b "$jar" "$base/securite/codes")
    [ "$c" = 302 ] || ko "codes de récupération accessibles avant $libelle -> $c"
    [ "$ERREURS" = 0 ] && echo "  connexion $email → $libelle forcé, aucune page avant : OK"
}

# L5 : « Relier un Hermes » avec le premier compte (pas encore enrôlé) et le pont du clone neuf.
verifier_relier() { # base depot nom_instance
    printf '%s\n' "$MDP" | "$2/scripts/e2e/relier-console.sh" "$1" "$EMAIL" "$3" || ko "relier un Hermes ($1)"
}

sail_exec() { (cd "$T/sail" && docker compose -p "$SAIL_PROJET" exec -T -u sail laravel.test "$@"); }

# Crée les 2 comptes avec la commande passée en arguments (préfixe docker), puis vérifie les deux parcours.
# Le mot de passe provisoire est lu dans la sortie de la commande et jamais affiché.
verifier_comptes() { # base commande...
    local base=$1; shift
    local sortie mdp2
    printf '%s\n' "$MDP" | "$@" php artisan hermes-os:compte --email="$EMAIL" --nom=Verif --mot-de-passe-stdin | sed 's/^/  /'
    sortie=$("$@" php artisan hermes-os:compte --email="$EMAIL2" --nom=Verif2 --provisoire </dev/null)
    mdp2=$(sed -n 's/^Mot de passe provisoire : //p' <<<"$sortie" | tr -d '\r')
    if [ -n "$mdp2" ]; then echo "  second compte $EMAIL2 créé (mot de passe provisoire non affiché)"; else ko "second compte non créé"; fi
    verifier_connexion "$base" "$EMAIL" "$MDP" /securite/totp "enrôlement TOTP"
    [ -n "$mdp2" ] && verifier_connexion "$base" "$EMAIL2" "$mdp2" /securite/mot-de-passe "changement du mot de passe"
}

echo "== 0. Branche $BR ($(git -C "$REPO" rev-parse --short "$BR")), dossier $T"

echo "== 1. Sail sur clone neuf (docker compose up -d seul)"
git clone -q -b "$BR" "$REPO" "$T/sail"
( cd "$T/sail" && APP_PORT=127.0.0.1:$SAIL_PORT VITE_PORT=$VITE_PORT VITE_PORT_PUBLIE=127.0.0.1:$VITE_PORT WWWUSER=$(id -u) WWWGROUP=$(id -g) \
    docker compose -p "$SAIL_PROJET" up -d --build >"$T/sail-up.log" 2>&1 ) || { ko "compose up"; tail -20 "$T/sail-up.log"; }
if attendre200 "http://127.0.0.1:$SAIL_PORT/connexion" 150; then
    # Sail lit .env (APP_URL=http://localhost) : on compare les redirections sur cette base.
    verifier_pages "http://localhost:$SAIL_PORT"
    (cd "$T/sail" && docker compose -p "$SAIL_PROJET" exec -T -u sail laravel.test php -v | head -1)
    if (cd "$T/sail" && docker compose -p "$SAIL_PROJET" exec -T -u sail laravel.test php artisan test >"$T/tests.log" 2>&1); then
        echo "  tests Pest (PHP 8.5, Sail) : $(grep -E 'Tests:' "$T/tests.log" | sed 's/\x1b\[[0-9;]*m//g' | tr -s ' ')"
    else
        ko "tests Pest dans Sail"; sed 's/\x1b\[[0-9;]*m//g' "$T/tests.log" | grep -vE '^\s*$' | tail -40
    fi
    verifier_comptes "http://localhost:$SAIL_PORT" sail_exec
    # L5 : comme le README, pilote distant dans .env puis redémarrage (artisan serve lit .env au démarrage).
    sed -i 's/^HERMES_PONT_DRIVER=.*/HERMES_PONT_DRIVER=distant/' "$T/sail/.env"
    (cd "$T/sail" && docker compose -p "$SAIL_PROJET" restart laravel.test >/dev/null 2>&1)
    if attendre200 "http://127.0.0.1:$SAIL_PORT/up" 30; then
        echo "  Sail : HERMES_PONT_DRIVER=distant (.env) puis redémarrage"
        verifier_relier "http://localhost:$SAIL_PORT" "$T/sail" sail-demo
    else
        ko "Sail : /up jamais en 200 après passage au pilote distant"
    fi
else
    ko "Sail : /connexion jamais en 200"
    (cd "$T/sail" && docker compose -p "$SAIL_PROJET" logs --tail 40 laravel.test)
fi

echo "== 2. Dockerfile de production (clone neuf)"
git clone -q -b "$BR" "$REPO" "$T/prod"
if docker build -q -t "$PROD_IMAGE" "$T/prod" >"$T/build.log" 2>&1; then
    # Cookies Secure : la vérification se fait en HTTP local, d'où SESSION_SECURE_COOKIE=false ici seulement.
    docker run -d --name "$PROD_NOM" -p "127.0.0.1:$PROD_PORT:8080" --read-only --tmpfs /tmp \
        -v "$PROD_VOLUME:/app/storage" -e APP_KEY="base64:$(head -c32 /dev/urandom | base64)" \
        -e APP_URL="http://127.0.0.1:$PROD_PORT" -e SESSION_SECURE_COOKIE=false -e HERMES_PONT_DRIVER=distant \
        "$PROD_IMAGE" >/dev/null
    if attendre200 "http://127.0.0.1:$PROD_PORT/up" 30; then
        verifier_pages "http://127.0.0.1:$PROD_PORT"
        verifier_statiques "http://127.0.0.1:$PROD_PORT"
        # Q2 : caches Laravel effectifs dans l'image (php artisan optimize au démarrage), puis TTFB de /connexion.
        caches=$(docker exec "$PROD_NOM" php artisan about --only=cache --json 2>/dev/null)
        for k in config events routes views; do
            grep -q "\"$k\":true" <<<"$caches" || ko "cache $k absent (php artisan optimize)"
        done
        ttfb=$(for _ in $(seq 1 10); do curl -s -o /dev/null -w '%{time_starttransfer}\n' "http://127.0.0.1:$PROD_PORT/connexion"; done | sort -n)
        echo "  caches config/events/routes/views : $(grep -o '"[a-z]*":\(true\|false\)' <<<"$caches" | tr '\n' ' ')"
        echo "  TTFB /connexion (10 requêtes, s) : min $(head -1 <<<"$ttfb") · médiane $(sed -n 5p <<<"$ttfb") · max $(tail -1 <<<"$ttfb")"
        verifier_comptes "http://127.0.0.1:$PROD_PORT" docker exec -i "$PROD_NOM"
        verifier_relier "http://127.0.0.1:$PROD_PORT" "$T/prod" prod-demo
        echo "  $(docker exec "$PROD_NOM" php -r 'echo "PHP ", PHP_VERSION;') / uid $(docker exec "$PROD_NOM" id -u)"
        for _ in $(seq 1 20); do
            etat=$(docker inspect --format '{{.State.Health.Status}}' "$PROD_NOM"); [ "$etat" = healthy ] && break; sleep 3
        done
        [ "$etat" = healthy ] && echo "  healthcheck : healthy" || ko "healthcheck : $etat"
    else
        ko "prod : /up jamais en 200"; docker logs "$PROD_NOM" 2>&1 | tail -30
    fi
else
    ko "docker build"; tail -30 "$T/build.log"
fi

SHA=$(git -C "$T/sail" rev-parse --short HEAD 2>/dev/null || git -C "$REPO" rev-parse --short "$BR")
[ "$FAIL" = 0 ] && echo "RÉSULTAT : OK $SHA" || echo "RÉSULTAT : ÉCHEC $SHA"
exit "$FAIL"
