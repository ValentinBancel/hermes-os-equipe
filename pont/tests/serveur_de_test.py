"""Lance le pont sur un faux Hermes (fixture) pour les tests d'intégration Laravel (PontClient).
Usage : python3 pont/tests/serveur_de_test.py <dossier_vide> <socket>
Écrit « pret » sur la sortie standard quand le socket écoute ; s'arrête sur SIGTERM ou fin de stdin.
Le secret de test est fixtures.SECRET_PONT ; le binaire hermes est factice (journal dans <dossier>/appels.jsonl)."""
from __future__ import annotations

import os
import signal
import sys
import threading

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(ICI))
sys.path.insert(0, ICI)

import fixtures  # noqa: E402
import hermes_os_pont as hp  # noqa: E402


def main() -> int:
    dossier, socket_chemin = sys.argv[1], sys.argv[2]
    home, dehors = os.path.join(dossier, "hermes"), os.path.join(dossier, "dehors")
    os.makedirs(home)
    os.makedirs(dehors)
    fixtures.construire(home, dehors)
    faux = fixtures.faux_hermes(os.path.join(dossier, "bin", "hermes"), os.path.join(dossier, "appels.jsonl"))
    serveur = hp.creer_serveur(hp.Pont(home, fixtures.SECRET_PONT, faux, delai_action=10,
                                               poles_chemin=os.path.join(home, "studio", "organisation", "poles.yaml")), socket_chemin)
    signal.signal(signal.SIGTERM, lambda *_: threading.Thread(target=serveur.shutdown).start())
    threading.Thread(target=lambda: (sys.stdin.read(), serveur.shutdown()), daemon=True).start()
    print("pret", flush=True)
    serveur.serve_forever()
    serveur.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
