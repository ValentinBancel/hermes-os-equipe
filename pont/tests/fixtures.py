"""Faux répertoire Hermes pour les tests du pont : mêmes schémas et formats qu'un vrai home Hermes
(relevés le 08/10/2026), plus des secrets « appâts » qui ne doivent jamais sortir du pont."""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import sqlite3
import stat
import time

SECRET_PONT = b"secret-de-test-du-pont-hermes-os-0123456789abcdef"

# Valeurs appâts : présentes dans des fichiers interdits (.env, auth.json, vault/…). Aucune ne doit sortir.
APPATS = [
    "APPAT-ENV-7f3c9a1e5b",
    "APPAT-AUTH-JSON-c41d",
    "APPAT-VAULT-99ab12",
    "APPAT-PAIRING-0e0e",
    "APPAT-CONFIG-PROFIL-5150",
    "APPAT-HOME-CONFIG-77",
]
# Secrets réalistes glissés dans des textes libres (carte, commentaire, sortie cron, log, Markdown) : masqués.
SECRETS_TEXTE = [
    "sk-ant-api03-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789",
    "ghp_1234567890abcdefghijABCDEFGHIJ123456",
    "xo" "xb-123456789012-abcdefghijklmnop",
    "AKIAIOSFODNN7EXAMPLE",
    "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U",
]

STATE_SCHEMA = """
CREATE TABLE schema_version (version INTEGER NOT NULL);
CREATE TABLE sessions (id TEXT PRIMARY KEY, source TEXT NOT NULL, user_id TEXT, session_key TEXT, chat_id TEXT,
  model TEXT, system_prompt TEXT, started_at REAL NOT NULL, ended_at REAL, end_reason TEXT,
  message_count INTEGER DEFAULT 0, tool_call_count INTEGER DEFAULT 0, input_tokens INTEGER DEFAULT 0,
  output_tokens INTEGER DEFAULT 0, cache_read_tokens INTEGER DEFAULT 0, cache_write_tokens INTEGER DEFAULT 0,
  reasoning_tokens INTEGER DEFAULT 0, estimated_cost_usd REAL, actual_cost_usd REAL, title TEXT,
  last_activity_at REAL, profile_name TEXT);
CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL, role TEXT NOT NULL,
  content TEXT, timestamp REAL NOT NULL);
CREATE TABLE session_model_usage (session_id TEXT NOT NULL, model TEXT NOT NULL, billing_provider TEXT DEFAULT '',
  api_call_count INTEGER DEFAULT 0, input_tokens INTEGER DEFAULT 0, output_tokens INTEGER DEFAULT 0,
  cache_read_tokens INTEGER DEFAULT 0, cache_write_tokens INTEGER DEFAULT 0, reasoning_tokens INTEGER DEFAULT 0,
  estimated_cost_usd REAL, actual_cost_usd REAL, cost_status TEXT, first_seen REAL, last_seen REAL);
"""

EXECUTIONS_SCHEMA = """
CREATE TABLE executions (id TEXT PRIMARY KEY, job_id TEXT NOT NULL, source TEXT, process_id TEXT, pid INTEGER,
  process_started_at TEXT, status TEXT, handoff_pending INTEGER, handoff_started_at TEXT, claimed_at TEXT,
  started_at TEXT, finished_at TEXT, error TEXT, delivery_outcome TEXT, scheduled_instant TEXT);
"""

POLES_YAML = """# Organisation des agents par pôle
commun_skills: [nextcloud-files, grounded-citations]
profils:
  # ---------------- Pôle Dev web ----------------
  cto:
    pole: dev
    role: lead
    model: claude-opus-5-5
    keep: [laravel-best-practices, github]
    toolsets_off: [image_gen, tts]
    description: "Lead tech / CTO du pôle Dev web : cadre le projet."
  dev:
    pole: dev
    role: executant
    model: claude-opus-5-5
    keep: [laravel-best-practices]
    description: "Développeur du pôle Dev web (Laravel, PHP, front)."
  qa:
    pole: dev
    role: auditeur
    model: claude-sonnet-5-5
  skillscout:
    pole: transverse
    role: support
    model: claude-sonnet-5-5
"""

CONFIG_PROFIL = """# config de profil (extrait)
database:
  path: state.db
model:
  # Default model
  default: "claude-opus-5-5"
  provider: anthropic
  api_key: "APPAT-CONFIG-PROFIL-5150"
skills:
  creation_nudge_interval: 15
  # external_dirs:
  #   - ~/.agents/skills
# =============================
# Agent Behavior
# =============================
  disabled:
    - github-désactivé
    - pdf
agent:
  max_turns: 90
"""


def _db(chemin: str, schema: str) -> sqlite3.Connection:
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    c = sqlite3.connect(chemin)
    c.execute("PRAGMA synchronous = OFF")  # fixture jetable : pas de fsync (tests rapides)
    c.execute("PRAGMA journal_mode = MEMORY")
    c.executescript(schema)
    return c


def _ecrire(chemin: str, contenu: str, mode: int | None = None) -> None:
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    with open(chemin, "w", encoding="utf-8") as f:
        f.write(contenu)
    if mode is not None:
        os.chmod(chemin, mode)


def _skill(racine: str, categorie: str, nom: str) -> None:
    _ecrire(os.path.join(racine, categorie, nom, "SKILL.md"), f"---\nname: {nom}\ndescription: test\n---\n# {nom}\n")


def construire(racine: str, dehors: str) -> dict:
    """Crée un faux home Hermes dans `racine` ; `dehors` est un dossier hors de Hermes (cible de liens)."""
    maintenant = int(time.time())
    h = racine

    # --- secrets appâts (fichiers interdits) ---
    _ecrire(f"{h}/.env", f"ANTHROPIC_API_KEY={APPATS[0]}\n", 0o600)
    _ecrire(f"{h}/.env.bak-1790496836", f"X={APPATS[0]}\n", 0o600)
    _ecrire(f"{h}/auth.json", json.dumps({"token": APPATS[1]}), 0o600)
    _ecrire(f"{h}/auth.lock", "1")
    _ecrire(f"{h}/vault/items.json", json.dumps({"v": APPATS[2]}), 0o600)
    _ecrire(f"{h}/pairing/discord.json", json.dumps({"p": APPATS[3]}), 0o600)
    _ecrire(f"{h}/profiles/dev/.env", f"K={APPATS[0]}\n", 0o600)
    _ecrire(f"{dehors}/.config/secret.txt", APPATS[5])
    _ecrire(f"{dehors}/hors-hermes.md", f"# Hors\n{APPATS[5]}\n")

    # --- gateway ---
    _ecrire(f"{h}/gateway_state.json", json.dumps({
        "pid": os.getpid(), "kind": "hermes-gateway", "argv": ["hermes", "gateway", f"--token={APPATS[0]}"],
        "hermes_home": h, "gateway_state": "running", "exit_reason": None, "active_agents": 2,
        "platforms": {"discord": {"state": "connected", "error_code": None, "error_message": None,
                                  "updated_at": "2026-10-08T16:52:25+00:00", "needs_attention": False,
                                  "retrying_since": None}},
        "updated_at": "2026-10-08T16:53:42+00:00", "code_version": "0.21.5",
        "served_profiles": ["default", "cto", "dev", "qa", "skillscout"],
    }))

    # --- state.db (profil principal + un profil) ---
    for chemin, profil in ((f"{h}/state.db", "default"), (f"{h}/profiles/dev/state.db", "dev")):
        c = _db(chemin, STATE_SCHEMA)
        c.execute("INSERT INTO schema_version VALUES (31)")
        for i, (source, modele) in enumerate((("cron", "claude-opus-5-5"), ("discord", "claude-sonnet-5-5"))):
            sid = f"2026100{i}_{profil}"
            debut = maintenant - 7200 * (i + 1)
            c.execute("INSERT INTO sessions (id, source, model, system_prompt, started_at, ended_at, message_count, "
                      "tool_call_count, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, "
                      "estimated_cost_usd, actual_cost_usd, title, last_activity_at, profile_name) "
                      "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                      (sid, source, modele, f"PROMPT {APPATS[0]}", debut, debut + 600, 12, 4, 1000, 200, 5000, 300,
                       0.5, 0.0, f"Session {i} token={SECRETS_TEXTE[0]}", debut + 600, profil))
            c.execute("INSERT INTO messages (session_id, role, content, timestamp) VALUES (?,?,?,?)",
                      (sid, "user", f"message privé {APPATS[0]}", debut))
            c.execute("INSERT INTO session_model_usage (session_id, model, input_tokens, output_tokens, "
                      "cache_read_tokens, cache_write_tokens, reasoning_tokens, estimated_cost_usd, actual_cost_usd, "
                      "first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                      (sid, modele, 1000, 200, 5000, 300, 10, 0.5, 0.0, debut, debut + 600))
        # Session sans ligne de consommation par modèle : comptée depuis la session elle-même.
        c.execute("INSERT INTO sessions (id, source, model, started_at, input_tokens, output_tokens, "
                  "last_activity_at, profile_name) VALUES (?,?,?,?,?,?,?,?)",
                  (f"seule_{profil}", "cli", None, maintenant - 300, 7, 3, maintenant - 200, profil))
        c.commit()
        c.close()

    # --- cron ---
    jobs = [
        {"id": "31cd7657668e", "name": "Veille — IA — Big Tech", "prompt": f"PROMPT {APPATS[0]}",
         "schedule": {"kind": "cron", "expr": "0 8 * * *", "display": "0 8 * * *"},
         "schedule_display": "0 8 * * *", "enabled": True, "state": "scheduled", "paused_at": None,
         "paused_reason": None, "next_run_at": "2026-10-09T08:00:00+02:00",
         "last_run_at": "2026-10-08T08:01:30+02:00", "last_status": "ok", "last_error": None,
         "last_delivery_error": None, "failure_streak": 0, "deliver": "discord:100000000000000001",
         "script": "", "workdir": None},
        {"id": "7b72a4dcb9a6", "name": "Rapport SEO hebdo", "prompt": "x",
         "schedule": {"kind": "cron", "expr": "0 18 * * 3"}, "schedule_display": "0 18 * * 3", "enabled": True,
         "state": "scheduled", "paused_at": None, "paused_reason": None, "next_run_at": None, "last_run_at": None,
         "last_status": "error", "last_error": f"HTTP 401 token={SECRETS_TEXTE[1]}",
         "last_delivery_error": "échec de livraison", "failure_streak": 2, "deliver": "discord:42"},
    ]
    _ecrire(f"{h}/cron/jobs.json", json.dumps({"jobs": jobs, "updated_at": "2026-10-08T16:52:00+02:00"}), 0o600)
    c = _db(f"{h}/cron/executions.db", EXECUTIONS_SCHEMA)
    c.execute("INSERT INTO executions (id, job_id, source, status, started_at, finished_at, error, delivery_outcome, "
              "scheduled_instant) VALUES (?,?,?,?,?,?,?,?,?)",
              ("e1", "31cd7657668e", "builtin", "completed", "2026-10-08T08:00:00+02:00",
               "2026-10-08T08:01:30+02:00", None, "delivered", "2026-10-08T06:00:00+00:00"))
    c.commit()
    c.close()
    _ecrire(f"{h}/cron/output/31cd7657668e/2026-10-08_08-01-28.md",
            f"# Veille\nRésultat. Clé trouvée : {SECRETS_TEXTE[0]}\n-----BEGIN OPENSSH PRIVATE KEY-----\n"
            "b3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----\n")
    os.symlink(f"{h}/.env", f"{h}/cron/output/31cd7657668e/2026-10-08_09-00-00.md")

    # --- studio ---
    m = f"{h}/studio/missions/2026-10-08-hermes-os"
    _ecrire(f"{m}/00-brief.md", "# hermes-os — brief\n\nConsole privée.\n")
    _ecrire(f"{m}/lots/05-pont.md", f"# Lot 05\n\nmot de passe : password: {SECRETS_TEXTE[3]}x\n")
    _ecrire(f"{m}/notes.txt", "pas du Markdown")
    _ecrire(f"{m}/.env", f"S={APPATS[0]}\n")
    os.symlink(f"{h}/.env", f"{m}/lien-env.md")
    os.symlink(f"{dehors}/hors-hermes.md", f"{m}/lien-dehors.md")
    os.symlink(dehors, f"{m}/dossier-dehors")
    os.symlink(f"{h}/vault", f"{h}/studio/missions/lien-vault")
    _ecrire(f"{h}/studio/missions/2026-10-01-site-vitrine-vb/00-brief.md", "# Site vitrine VB\n")
    _ecrire(f"{h}/studio/organisation/poles.yaml", POLES_YAML)

    # --- profils ---
    _ecrire(f"{h}/config.yaml", CONFIG_PROFIL.replace("claude-opus-5-5", "claude-opus-5-5"), 0o600)
    for p in ("cto", "dev", "qa", "skillscout"):
        _ecrire(f"{h}/profiles/{p}/config.yaml", CONFIG_PROFIL, 0o600)
        for nom in ("laravel-best-practices", "github-désactivé", "pdf", "spike"):
            _skill(f"{h}/profiles/{p}/skills", "dev", nom)
    for nom in ("hermes-agent", "pdf"):
        _skill(f"{h}/skills", "outils", nom)

    # --- logs ---
    recent = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(maintenant - 600))
    ancien = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(maintenant - 3 * 86400))
    _ecrire(f"{h}/logs/errors.log",
            f"{ancien},001 ERROR old.module: vieille erreur\n"
            f"{recent},002 WARNING hermes_cli.tools_config: platform 'teams' has no valid toolsets\n"
            f"{recent},003 ERROR gateway.run: échec Authorization: Bearer {SECRETS_TEXTE[2]}\n"
            "  Traceback (most recent call last):\n", 0o600)
    _ecrire(f"{h}/profiles/dev/logs/errors.log", f"{recent},004 ERROR cron.scheduler: job failed\n", 0o600)

    return {"home": h, "maintenant": maintenant}


def faux_hermes(chemin: str, journal: str, code: int = 0, dormir: float = 0) -> str:
    """Script `hermes` factice : consigne argv et stdin en JSON, renvoie une sortie contenant un secret."""
    _ecrire(chemin, f"""#!/usr/bin/env python3
import json, sys, time, os
time.sleep({dormir})
entree = sys.stdin.read()
with open({journal!r}, "a", encoding="utf-8") as f:
    f.write(json.dumps({{"argv": sys.argv[1:], "stdin": entree, "home": os.environ.get("HERMES_HOME"),
                        "env": sorted(os.environ)}}) + "\\n")
print("Created t_deadbeef (ready) token={SECRETS_TEXTE[1]}")
sys.exit({code})
""")
    os.chmod(chemin, stat.S_IRWXU)
    return chemin


def signer(methode: str, chemin: str, corps: bytes = b"", horodatage: int | None = None,
           secret: bytes = SECRET_PONT) -> dict:
    ts = str(int(time.time()) if horodatage is None else horodatage)
    sig = hmac.new(secret, f"{ts}\n{methode}\n{chemin}\n".encode() + corps, hashlib.sha256).hexdigest()
    return {"X-Pont-Horodatage": ts, "X-Pont-Signature": sig}
