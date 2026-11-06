"""Home Hermes de démonstration, neutre (L4) : aucune donnée réelle, aucun secret.

Les fichiers texte sont versionnés dans pont/tests/demo-home/ ; ce script les copie dans un dossier cible et y
ajoute les bases SQLite (sessions, exécutions) datées par rapport à maintenant, pour que l'accueil ait « du jour ».

    python3 pont/tests/demo_home.py /tmp/demo-home
"""

from __future__ import annotations

import os
import shutil
import sqlite3
import sys
import time

ICI = os.path.dirname(os.path.abspath(__file__))
SOURCE = os.path.join(ICI, "demo-home")
sys.path.insert(0, ICI)

from fixtures import EXECUTIONS_SCHEMA, STATE_SCHEMA  # noqa: E402

PROFILS = ("default", "dev", "redaction")
MODELES = ("claude-sonnet-5-5", "claude-opus-5-5")


def _base(chemin: str, schema: str) -> sqlite3.Connection:
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    c = sqlite3.connect(chemin)
    c.executescript(schema)
    return c


def construire(cible: str) -> str:
    shutil.copytree(SOURCE, cible, dirs_exist_ok=True)
    maintenant = time.time()

    for profil in PROFILS:
        chemin = os.path.join(cible, "state.db") if profil == "default" else os.path.join(cible, "profiles", profil, "state.db")
        c = _base(chemin, STATE_SCHEMA)
        c.execute("INSERT INTO schema_version VALUES (31)")
        for i in range(3):
            debut = maintenant - 3600 * (i + 1)
            sid = f"demo_{profil}_{i}"
            modele = MODELES[i % 2]
            c.execute("INSERT INTO sessions (id, source, model, started_at, ended_at, message_count, tool_call_count, "
                      "input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, estimated_cost_usd, title, "
                      "last_activity_at, profile_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                      (sid, ("cli", "cron", "discord")[i], modele, debut, debut + 900, 10 + i, 3, 1200 * (i + 1),
                       300 * (i + 1), 4000, 200, 0.2 * (i + 1), f"Session de démonstration {i + 1}", debut + 900, profil))
            c.execute("INSERT INTO session_model_usage (session_id, model, api_call_count, input_tokens, output_tokens, "
                      "cache_read_tokens, cache_write_tokens, reasoning_tokens, estimated_cost_usd, first_seen, last_seen) "
                      "VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                      (sid, modele, 5, 1200 * (i + 1), 300 * (i + 1), 4000, 200, 0, 0.2 * (i + 1), debut, debut + 900))
        c.commit()
        c.close()

    c = _base(os.path.join(cible, "cron", "executions.db"), EXECUTIONS_SCHEMA)
    hier = time.strftime("%Y-%m-%dT08:00:00+00:00", time.gmtime(maintenant - 86400))
    c.execute("INSERT INTO executions (id, job_id, source, status, started_at, finished_at, delivery_outcome, "
              "scheduled_instant) VALUES (?,?,?,?,?,?,?,?)",
              ("demo-e1", "d0d0d0d0d0d1", "builtin", "completed", hier, hier, "delivered", hier))
    c.commit()
    c.close()

    for racine, _, fichiers in os.walk(cible):
        os.chmod(racine, 0o700)
        for f in fichiers:
            os.chmod(os.path.join(racine, f), 0o600)
    return cible


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("usage : demo_home.py <dossier cible>")
    print(construire(sys.argv[1]))
