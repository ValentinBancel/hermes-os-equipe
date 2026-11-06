#!/usr/bin/env bash
# Scénario « Relier un Hermes » de bout en bout contre une console déjà démarrée (L5), comme un utilisateur :
#   1. connexion du compte (créé en CLI) → enrôlement TOTP → Compte → « Relier un Hermes » → code d'appairage ;
#   2. `hermes_os_pont.py relier <url> --code … --sans-systemd` sur un home Hermes de démonstration ;
#   3. boucle `distant` (relevés poussés toutes les 2 s) ;
#   4. Compte affiche l'instance « En ligne », l'accueil (connecté) affiche des valeurs du home de démonstration ;
#      `hermes_os_pont.py etat` dit « relié ».
# Utilisé par scripts/verifier-clone-neuf.sh (Sail et image de production) et par la CI (job e2e).
# La console doit tourner avec HERMES_PONT_DRIVER=distant. Le pont tourne sur cet hôte (python3 ≥ 3.11), avec un
# HOME jetable : rien n'est écrit dans le vrai home (ni service systemd, ni identité). Aucun secret affiché :
# mot de passe lu sur l'entrée standard, clé TOTP et code d'appairage jamais écrits dans la sortie.
#
#   printf '%s\n' "$MDP" | scripts/e2e/relier-console.sh <url-console> <email> [nom-instance]
set -uo pipefail

RACINE=$(cd "$(dirname "$0")/../.." && pwd)
URL=${1:?url de la console}
EMAIL=${2:?email du compte}
NOM=${3:-verif-demo}
# Accueil : état de la gateway du home de démonstration (pont/tests/demo_home.py), et toutes les lectures relayées
# par le pont (aucun bloc en attente ni en erreur). Rien qui dépende de l'heure : les sessions de démonstration
# (1 à 3 h avant) tombent la veille juste après minuit.
ATTENDUS=("Gateway en marche" "!Donnée en cours de récupération" "!Pont injoignable")
T=$(mktemp -d "${TMPDIR:-/tmp}/hermes-os-relier.XXXXXX")
PONT=""

nettoyer() {
    [ -n "$PONT" ] && kill "$PONT" 2>/dev/null && wait "$PONT" 2>/dev/null
    rm -rf "$T"
}
trap nettoyer EXIT

ko() { echo "  KO $*"; [ -f "$T/pont.log" ] && tail -15 "$T/pont.log" | sed 's/^/    /'; exit 1; }
pont() { HOME="$T/home-utilisateur" XDG_CONFIG_HOME="$T/home-utilisateur/.config" \
    python3 "$RACINE/pont/hermes_os_pont.py" "$@" --hermes-home "$T/hermes-home" --hermes-bin "$T/hermes" --sans-coffre; }

mkdir -p "$T/home-utilisateur"
python3 "$RACINE/pont/tests/demo_home.py" "$T/hermes-home" >/dev/null || ko "home Hermes de démonstration"
python3 -c "import sys; sys.path.insert(0, sys.argv[1]); import fixtures; fixtures.faux_hermes(sys.argv[2], sys.argv[3])" \
    "$RACINE/pont/tests" "$T/hermes" "$T/hermes.journal" || ko "faux binaire hermes"

# 1. Code d'appairage obtenu par le navigateur (session dans $T/session, jamais affichée).
debut=$SECONDS
CODE=$(python3 "$RACINE/scripts/e2e/parcours_console.py" code "$URL" "$EMAIL" "$T/session") || ko "parcours navigateur jusqu'au code d'appairage"
[[ "$CODE" =~ ^[A-Z0-9]{4}-[A-Z0-9]{4}$ ]] || ko "code d'appairage mal formé"
echo "  connexion $EMAIL → TOTP enrôlé → Compte → « Relier un Hermes » → code d'appairage reçu (non affiché)"

# 2. relier
pont relier "$URL" --code "$CODE" --nom "$NOM" --sans-systemd >"$T/relier.log" 2>&1 || { cat "$T/relier.log"; ko "relier"; }
grep -q "relié à $URL" "$T/relier.log" || { cat "$T/relier.log"; ko "relier : confirmation absente"; }
echo "  hermes_os_pont.py relier $URL --code … --sans-systemd : OK"

# 3. boucle distant
# exec : $! est bien le processus python (sinon kill ne touche que le sous-shell et le pont survit) ;
# 9>&- : le pont ne garde pas le verrou de scripts/verifier-clone-neuf.sh.
(exec 9>&-; HOME="$T/home-utilisateur" XDG_CONFIG_HOME="$T/home-utilisateur/.config" exec python3 \
    "$RACINE/pont/hermes_os_pont.py" distant --intervalle 2 --hermes-home "$T/hermes-home" --hermes-bin "$T/hermes" \
    --sans-coffre) >"$T/pont.log" 2>&1 &
PONT=$!

# 4. ce que voit l'utilisateur (relevés poussés en quelques secondes)
ok=0
for _ in $(seq 1 20); do
    kill -0 "$PONT" 2>/dev/null || ko "boucle distant arrêtée"
    if python3 "$RACINE/scripts/e2e/parcours_console.py" verifier "$URL" "$T/session" "$NOM" "${ATTENDUS[@]}" >"$T/verif.log" 2>&1; then
        ok=1; break
    fi
    sleep 2
done
[ "$ok" = 1 ] || { cat "$T/verif.log"; ko "console sans l'instance ou sans les données de démonstration"; }
echo "  $(cat "$T/verif.log")"
pont etat >"$T/etat.json" 2>&1 || ko "hermes_os_pont.py etat"
python3 -c "import json, sys; e = json.load(open(sys.argv[1])); sys.exit(0 if e.get('relie') and e.get('nom') == sys.argv[2] else 1)" \
    "$T/etat.json" "$NOM" || { cat "$T/etat.json"; ko "etat : non relié"; }
echo "  hermes_os_pont.py etat : relié (« $NOM »)"
echo "  relier un Hermes de bout en bout : OK en $((SECONDS - debut)) s"
