#!/bin/sh
# Installeur du pont hermes-os (L4, R12) — servi par la console, URL injectée au téléchargement.
# Usage : voir la commande affichée dans Compte (téléchargement de ce script puis exécution par sh).
set -eu

# URL validée par la console (HTTPS, ou HTTP en bouclage) et citée en apostrophes : rien n'y est interprété.
CONSOLE='__CONSOLE__'
DOSSIER="${HERMES_OS_PONT_DOSSIER:-$HOME/.local/share/hermes-os-pont}"
MODULES="__MODULES__"

command -v python3 >/dev/null 2>&1 || { echo "python3 introuvable (3.11 minimum)." >&2; exit 1; }
python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 11) else 1)' \
  || { echo "python3 >= 3.11 requis (trouvé : $(python3 -V 2>&1))." >&2; exit 1; }

umask 077
mkdir -p "$DOSSIER"
for m in $MODULES; do
  # HTTPS exigé (HTTP seulement si la console est en bouclage) ; une redirection ne peut mener qu'en HTTPS.
  curl --proto '__CURL_PROTO__' --proto-redir '=https' -fsSL "$CONSOLE/pont/fichiers/$m" -o "$DOSSIER/$m.tmp"
  mv "$DOSSIER/$m.tmp" "$DOSSIER/$m"
done
chmod 0700 "$DOSSIER"
echo "Pont installé dans $DOSSIER."

# Le code d'appairage est lu sur le terminal (le script lui-même arrive par un tube). Sans terminal de contrôle
# (cron, ssh -T, CI), /dev/tty existe mais ne s'ouvre pas : on le teste vraiment avant de s'en servir.
if ( : < /dev/tty ) 2>/dev/null; then
  exec python3 "$DOSSIER/hermes_os_pont.py" relier "$CONSOLE" "$@" < /dev/tty
fi
case " $* " in
  *" --code "*|*" --code="*) exec python3 "$DOSSIER/hermes_os_pont.py" relier "$CONSOLE" "$@" ;;
esac
echo "Pas de terminal pour saisir le code d'appairage : relancez depuis un terminal ou avec --code." >&2
exit 1
