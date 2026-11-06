#!/usr/bin/env python3
"""hermes-os-pont : API JSON locale entre la console hermes-os et Hermes (ADR 0002, option 2).

- HTTP sur socket Unix uniquement (permissions 0660), aucun port TCP.
- Chaque requête est signée : X-Pont-Horodatage (secondes Unix) et
  X-Pont-Signature = hex(HMAC-SHA256(secret, "<horodatage>\\n<MÉTHODE>\\n<chemin?requête>\\n<corps>")),
  fenêtre de 30 s, comparaison à temps constant, rejeu refusé pour les POST.
- Lectures : routes GET /v1/... en liste blanche. SQLite ouvert en lecture seule (mode=ro, query_only),
  colonnes nommées, chemins résolus par realpath et confinés, aucun accès à .env, auth.json, ~/.config,
  vault/, pairing/, auth.lock. Tout texte libre passe par le masqueur de secrets.
- Écritures : CLI officielle `hermes` uniquement (tâches planifiées), liste fermée d'actions, arguments validés,
  subprocess sans shell, délai 30 s. Aucune écriture directe dans un fichier ou une base de Hermes.

Python 3.11+ (stdlib uniquement). Usage : python3 hermes_os_pont.py --help
"""
from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import hashlib
import hmac
import json
import logging
import os
import re
import shutil
import socket
import socketserver
import sqlite3
import stat
import subprocess
import sys
import threading
import time
import urllib.parse
from collections.abc import Iterator
from http.server import BaseHTTPRequestHandler
from zoneinfo import ZoneInfo

VERSION = "1.0.0"
FENETRE_SECONDES = 30
CORPS_MAX = 64 * 1024  # 4 000 caractères non ASCII échappés en JSON (\uXXXX) tiennent largement
SORTIE_MAX = 256 * 1024
FUSEAU = ZoneInfo("Europe/Paris")

log = logging.getLogger("hermes-os-pont")

# ---------------------------------------------------------------------------
# Masqueur de secrets
# ---------------------------------------------------------------------------
MASQUE = "[masqué]"

_MOTIFS_SECRETS: list[tuple[re.Pattern[str], str]] = [
    # Clés PEM (bloc complet, ou en-tête seul si le bloc est tronqué).
    (re.compile(r"-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|\Z)", re.S), MASQUE),
    # Identifiants dans une URL : schéma://utilisateur:<mot de passe>@hôte
    (re.compile(r"(?i)\b([a-z][a-z0-9+.-]*://[^\s:/@]+:)[^\s@/]+@"), r"\1" + MASQUE + "@"),
    # En-tête Authorization.
    (re.compile(r"(?i)\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+/=-]{8,}"), r"\1 " + MASQUE),
    # JWT.
    (re.compile(r"\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}"), MASQUE),
    # OpenAI / Anthropic / OpenRouter… (sk-…, sk-ant-…, sk-proj-…, sk-or-…).
    (re.compile(r"\bsk-[A-Za-z0-9_-]{12,}"), MASQUE),
    # GitHub.
    (re.compile(r"\bgh[pousr]_[A-Za-z0-9]{20,}"), MASQUE),
    (re.compile(r"\bgithub_pat_[A-Za-z0-9_]{20,}"), MASQUE),
    # Slack.
    (re.compile(r"\bxox[abposre]-[A-Za-z0-9-]{8,}"), MASQUE),
    (re.compile(r"https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+"), MASQUE),
    # AWS.
    (re.compile(r"\b(?:AKIA|ASIA|AGPA|AIDA|AROA)[0-9A-Z]{16}"), MASQUE),
    # Google API.
    (re.compile(r"\bAIza[0-9A-Za-z_-]{30,}"), MASQUE),
    # Jetons de bot Discord.
    (re.compile(r"\b[MNO][A-Za-z0-9_-]{23,27}\.[A-Za-z0-9_-]{6}\.[A-Za-z0-9_-]{27,}"), MASQUE),
    # Webhooks Discord.
    (re.compile(r"https://(?:\w+\.)?discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_-]+"), MASQUE),
    # Clé d'application Laravel.
    (re.compile(r"\bbase64:[A-Za-z0-9+/]{40,}={0,2}"), MASQUE),
]

_CLES_SENSIBLES = (
    # Précédé d'un début de mot ou d'un « _ » : HERMES_PONT_SECRET, DB_PASSWORD, GITHUB_TOKEN…
    r"(?:\b|(?<=_))(?:password|passwd|pwd|pass|mot[_ -]?de[_ -]?passe|mdp|token|access[_-]?token|refresh[_-]?token"
    r"|secret|client[_-]?secret|api[_-]?key|apikey|access[_-]?key|private[_-]?key|app[_-]?key|authorization)"
)

# clé=valeur / "clé": "valeur" / clé : valeur (texte libre). Volontairement large : mieux vaut masquer un
# mot de trop dans une sortie que laisser passer un mot de passe. Ne touche pas `Password::x`, `$var`,
# `fn(`, `[…]`, ni une autre « clé: » (titres YAML).
_MOTIF_CLE_VALEUR = re.compile(
    r"(?i)(" + _CLES_SENSIBLES + r"[\"']?\s*[:=]\s*[\"']?)"
    r"(?![:=])(?!\[masqué\])(?![\w-]+\s*[:=]\s)(?![$({\[<`])[^\s\"',;&}]{3,}"
)

# Détecteur strict pour le dépôt : une valeur littérale qui ressemble à un vrai secret (≥ 12 caractères,
# lettres ET chiffres), affectée à une clé sensible.
MOTIF_CLE_VALEUR_LITTERALE = re.compile(
    r"(?i)" + _CLES_SENSIBLES + r"[\"']?\s*[:=]>?\s*[\"']"
    r"(?=[A-Za-z0-9+/_.=-]*\d)(?=[A-Za-z0-9+/_.=-]*[A-Za-z])[A-Za-z0-9+/_.=-]{12,}[\"']"
)


def secrets_formats(texte: str) -> list[str]:
    """Noms des motifs « à format reconnaissable » trouvés (sans renvoyer les valeurs)."""
    trouves = [m.pattern[:30] for m, _ in _MOTIFS_SECRETS if m.search(texte)]
    if MOTIF_CLE_VALEUR_LITTERALE.search(texte):
        trouves.append("clé=valeur littérale")
    return trouves


def masquer(texte: str | None) -> str | None:
    """Remplace tout motif de secret connu par « [masqué] ». Idempotent."""
    if texte is None:
        return None
    if not isinstance(texte, str):
        texte = str(texte)
    for motif, remplacement in _MOTIFS_SECRETS:
        texte = motif.sub(remplacement, texte)
    return _MOTIF_CLE_VALEUR.sub(r"\1" + MASQUE, texte)


def masquer_json(valeur):
    """Masque récursivement les chaînes d'une structure JSON."""
    if isinstance(valeur, str):
        return masquer(valeur)
    if isinstance(valeur, list):
        return [masquer_json(v) for v in valeur]
    if isinstance(valeur, dict):
        return {k: masquer_json(v) for k, v in valeur.items()}
    return valeur


# ---------------------------------------------------------------------------
# Erreurs et chemins
# ---------------------------------------------------------------------------
class ErreurHttp(Exception):
    def __init__(self, statut: int, message: str):
        super().__init__(message)
        self.statut = statut
        self.message = message


NOMS_INTERDITS = {".env", "auth.json", "auth.lock", "vault", "pairing", ".config", ".ssh", ".git"}
RE_SEGMENT = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")
RE_JOB = re.compile(r"^[a-f0-9]{12}$")
RE_PROFIL = re.compile(r"^[a-z0-9][a-z0-9_-]{0,63}$")
RE_SORTIE = re.compile(r"^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.md$")


def segment_sur(segment: str, motif: re.Pattern[str] = RE_SEGMENT) -> str:
    if not isinstance(segment, str) or not motif.match(segment) or ".." in segment:
        raise ErreurHttp(404, "Introuvable.")
    if segment.lower() in NOMS_INTERDITS or segment.lower().startswith(".env"):
        raise ErreurHttp(404, "Introuvable.")
    return segment


def chemin_confine(base: str, *parties: str) -> str:
    """Joint `parties` à `base`, résout les liens et refuse tout ce qui sort de `base`
    ou touche un nom interdit (même via un lien symbolique)."""
    racine = os.path.realpath(base)
    cible = os.path.realpath(os.path.join(racine, *parties))
    if cible != racine and os.path.commonpath([racine, cible]) != racine:
        raise ErreurHttp(404, "Introuvable.")
    for nom in cible.split(os.sep):
        if nom.lower() in NOMS_INTERDITS or nom.lower().startswith(".env"):
            raise ErreurHttp(404, "Introuvable.")
    return cible


@contextlib.contextmanager
def ouvrir_ro(chemin: str) -> Iterator[sqlite3.Connection]:
    """SQLite en lecture seule stricte (le pont ne peut rien écrire, même par erreur), fermée en sortie."""
    uri = "file:" + urllib.parse.quote(chemin) + "?mode=ro"
    connexion = sqlite3.connect(uri, uri=True, timeout=2)
    try:
        connexion.execute("PRAGMA query_only = 1")
        connexion.row_factory = sqlite3.Row
        yield connexion
    finally:
        connexion.close()


def echec(message: str):
    raise ValueError(message)


def lire_json(chemin: str):
    with open(chemin, encoding="utf-8") as f:
        return json.load(f)


def iso_depuis_epoch(valeur) -> str | None:
    if valeur in (None, ""):
        return None
    try:
        return dt.datetime.fromtimestamp(float(valeur), tz=FUSEAU).isoformat(timespec="seconds")
    except (TypeError, ValueError, OSError):
        return None


def tronquer(texte: str | None, n: int) -> str | None:
    if texte is None:
        return None
    return texte if len(texte) <= n else texte[: n - 1] + "…"


RE_ACTION = re.compile(r"^[a-z]{1,20}\.[a-z_]{1,30}$")
# Acteur = e-mail du compte hermes-os à l'origine de l'action (L3) : sans espace ni caractère de contrôle,
# pour qu'il ne puisse pas forger de ligne dans le journal du pont.
RE_ACTEUR = re.compile(r"^[^\s@\x00-\x1f\x7f]{1,64}@[^\s@\x00-\x1f\x7f]{1,190}$")


def acteur(corps: bytes) -> str:
    """Champ `acteur` du corps d'une action, tel que journalisé ; « inconnu » s'il manque ou est invalide.

    Purement informatif : n'autorise ni ne refuse rien (l'authentification reste la signature HMAC).
    """
    try:
        donnees = json.loads(corps.decode("utf-8") or "{}")
    except (UnicodeDecodeError, json.JSONDecodeError):
        return "inconnu"
    valeur = donnees.get("acteur") if isinstance(donnees, dict) else None
    return valeur if isinstance(valeur, str) and RE_ACTEUR.match(valeur) else "inconnu"


# ---------------------------------------------------------------------------
# Mini-lecteur YAML (sous-ensemble de poles.yaml) — stdlib uniquement
# ---------------------------------------------------------------------------
def _sans_commentaire(ligne: str) -> str:
    dans, quote = False, ""
    for i, c in enumerate(ligne):
        if c in "\"'" and (not dans or c == quote):
            dans, quote = (not dans), c
        elif c == "#" and not dans and (i == 0 or ligne[i - 1] in " \t"):
            return ligne[:i].rstrip()
    return ligne.rstrip()


def _scalaire(valeur: str):
    valeur = valeur.strip()
    if valeur.startswith("[") and valeur.endswith("]"):
        interieur = valeur[1:-1].strip()
        return [_scalaire(x) for x in interieur.split(",") if x.strip()] if interieur else []
    if len(valeur) >= 2 and valeur[0] == valeur[-1] and valeur[0] in "\"'":
        corps = valeur[1:-1]
        return corps.replace('\\"', '"') if valeur[0] == '"' else corps.replace("''", "'")
    if valeur in ("null", "~", ""):
        return None
    if valeur in ("true", "false"):
        return valeur == "true"
    return valeur


def lire_yaml_simple(texte: str) -> dict:
    """Mappings imbriqués par indentation, scalaires, listes en ligne [a, b] et listes « - x »."""
    racine: dict = {}
    pile: list[tuple[int, object]] = [(-1, racine)]
    attente: tuple[dict, str, int] | None = None
    for brute in texte.splitlines():
        ligne = _sans_commentaire(brute)
        if not ligne.strip():
            continue
        indent = len(ligne) - len(ligne.lstrip(" "))
        contenu = ligne.strip()
        while pile and indent <= pile[-1][0]:
            pile.pop()
        if attente is not None:
            parent, cle, ind_parent = attente
            attente = None
            if indent > ind_parent:
                neuf: object = [] if contenu.startswith("- ") or contenu == "-" else {}
                parent[cle] = neuf
                pile.append((ind_parent, neuf))
        conteneur = pile[-1][1]
        if contenu.startswith("- ") or contenu == "-":
            if isinstance(conteneur, list):
                conteneur.append(_scalaire(contenu[2:]))
            continue
        if ":" not in contenu or not isinstance(conteneur, dict):
            continue
        cle, _, valeur = contenu.partition(":")
        cle = cle.strip().strip("\"'")
        if valeur.strip() == "":
            conteneur[cle] = None
            attente = (conteneur, cle, indent)
        else:
            conteneur[cle] = _scalaire(valeur)
    return racine


def extraire_config_profil(texte: str) -> dict:
    """De config.yaml, ne garde que model.default et skills.disabled (rien d'autre)."""
    modele, desactives = None, []
    section, sous = None, None
    for brute in texte.splitlines():
        ligne = _sans_commentaire(brute)
        if not ligne.strip():
            continue
        indent = len(ligne) - len(ligne.lstrip(" "))
        contenu = ligne.strip()
        if indent == 0:
            section = contenu.split(":", 1)[0] if contenu.endswith(":") else None
            sous = None
            continue
        if section == "model" and indent == 2 and contenu.split(":", 1)[0] in ("default", "model"):
            if modele is None or contenu.startswith("default:"):
                modele = _scalaire(contenu.split(":", 1)[1])
        elif section == "skills":
            if indent == 2:
                sous = contenu.split(":", 1)[0]
                if sous == "disabled" and contenu.split(":", 1)[1].strip().startswith("["):
                    desactives.extend(_scalaire(contenu.split(":", 1)[1]) or [])
            elif sous == "disabled" and contenu.startswith("- "):
                desactives.append(str(_scalaire(contenu[2:])))
    return {"modele": modele if isinstance(modele, str) else None, "desactives": set(desactives)}


# ---------------------------------------------------------------------------
# Le pont
# ---------------------------------------------------------------------------
COLONNES_ATTENDUES = {
    "state.sessions": ["id", "source", "model", "profile_name", "title", "started_at", "ended_at",
                       "last_activity_at", "message_count", "tool_call_count", "input_tokens", "output_tokens",
                       "cache_read_tokens", "cache_write_tokens", "reasoning_tokens", "estimated_cost_usd",
                       "actual_cost_usd", "end_reason"],
    "state.session_model_usage": ["session_id", "model", "input_tokens", "output_tokens", "cache_read_tokens",
                                  "cache_write_tokens", "reasoning_tokens", "estimated_cost_usd",
                                  "actual_cost_usd", "last_seen"],
    "cron.executions": ["id", "job_id", "source", "status", "started_at", "finished_at", "error",
                        "delivery_outcome", "scheduled_instant"],
}
CLES_JOB = ["id", "name", "schedule_display", "enabled", "state", "next_run_at", "last_run_at", "last_status"]


class Pont:
    def __init__(self, hermes_home: str, secret: bytes | None, hermes_bin: str, delai_action: float = 90.0,
                 poles_chemin: str | None = None):
        # secret : celui du socket local ; None en mode distant (les requêtes viennent de la boucle, pas du réseau).
        if secret is not None and len(secret) < 32:
            raise ValueError("Secret partagé trop court (32 octets minimum).")
        self.home = os.path.realpath(hermes_home)
        # R11 : poles.yaml optionnel (HERMES_OS_POLES) ; sinon un pôle par profil.
        self.poles_chemin = poles_chemin
        self.secret = secret
        self.hermes_bin = hermes_bin
        self.delai_action = delai_action
        self._signatures_vues: dict[str, float] = {}
        self._verrou = threading.Lock()
        self.messagerie = None  # activée par --messagerie (hermes_os_messagerie.Messagerie)
        self.coffre = None  # coffre de Hermes (hermes_os_coffre.Coffre), désactivable par --sans-coffre

    # -- chemins en liste blanche ------------------------------------------
    def _p(self, *parties: str) -> str:
        return chemin_confine(self.home, *parties)

    def profils_existants(self) -> list[str]:
        racine = self._p("profiles")
        noms = ["default"]
        if os.path.isdir(racine):
            noms += [n for n in sorted(os.listdir(racine))
                     if RE_PROFIL.match(n) and os.path.isdir(os.path.join(racine, n))
                     and not os.path.islink(os.path.join(racine, n))]
        return noms

    def _jobs(self) -> list[dict]:
        chemin = self._p("cron", "jobs.json")
        if not os.path.isfile(chemin):
            return []
        donnees = lire_json(chemin)
        jobs = donnees.get("jobs", []) if isinstance(donnees, dict) else donnees
        return [j for j in jobs if isinstance(j, dict)]

    # -- authentification ---------------------------------------------------
    def signer(self, horodatage: str, methode: str, chemin: str, corps: bytes) -> str:
        message = f"{horodatage}\n{methode.upper()}\n{chemin}\n".encode() + corps
        return hmac.new(self.secret, message, hashlib.sha256).hexdigest()

    def verifier(self, entetes, methode: str, chemin: str, corps: bytes, maintenant: float | None = None) -> None:
        """Vérifie la signature (secret partagé avec la console)."""
        horodatage = entetes.get("X-Pont-Horodatage") or ""
        signature = entetes.get("X-Pont-Signature") or ""
        maintenant = time.time() if maintenant is None else maintenant
        if self.secret is None:
            raise ErreurHttp(401, "Signature absente.")
        if not re.fullmatch(r"\d{9,11}", horodatage) or not re.fullmatch(r"[a-f0-9]{64}", signature):
            raise ErreurHttp(401, "Signature absente.")
        if abs(maintenant - int(horodatage)) > FENETRE_SECONDES:
            raise ErreurHttp(401, "Signature expirée.")
        attendue = self.signer(horodatage, methode, chemin, corps)
        if not hmac.compare_digest(attendue, signature):
            raise ErreurHttp(401, "Signature invalide.")
        if methode == "POST":
            with self._verrou:
                for s, t in list(self._signatures_vues.items()):
                    if maintenant - t > 2 * FENETRE_SECONDES:
                        del self._signatures_vues[s]
                if signature in self._signatures_vues:
                    raise ErreurHttp(401, "Requête rejouée.")
                self._signatures_vues[signature] = maintenant

    # -- routage --------------------------------------------------------------
    def traiter(self, methode: str, cible: str, entetes, corps: bytes) -> tuple[int, dict]:
        try:
            if len(corps) > CORPS_MAX:
                raise ErreurHttp(413, "Corps trop volumineux.")
            self.verifier(entetes, methode, cible, corps)
        except ErreurHttp as e:
            return e.statut, {"erreur": e.message}
        return self.executer(methode, cible, corps)

    def executer(self, methode: str, cible: str, corps: bytes = b"") -> tuple[int, dict]:
        """Route une requête déjà authentifiée : signature du socket (traiter) ou console distante (boucle)."""
        try:
            if len(corps) > CORPS_MAX:
                raise ErreurHttp(413, "Corps trop volumineux.")
            url = urllib.parse.urlsplit(cible)
            brut = url.path
            if "\x00" in brut or "\\" in brut or "//" in brut:
                raise ErreurHttp(404, "Introuvable.")
            # Décodage unique : un « %2e » doublement encodé reste « %2e » et échoue à la validation.
            segments = [urllib.parse.unquote(s) for s in brut.split("/")[1:]]
            requete = urllib.parse.parse_qs(url.query)
            if not segments or segments[0] != "v1":
                raise ErreurHttp(404, "Introuvable.")
            seg = segments[1:]
            if methode == "GET":
                return 200, self._lecture(seg, requete)
            if methode == "POST" and len(seg) == 2 and seg[0] == "actions":
                log.info("action %s demandée par %s", seg[1] if RE_ACTION.match(seg[1]) else "?", acteur(corps))
                if seg[1].startswith("messagerie."):
                    return self._action_messagerie(seg[1], corps)
                if seg[1].startswith("vault."):
                    return self._action_coffre(seg[1], corps)
                return self._action(seg[1], corps)
            raise ErreurHttp(405 if methode not in ("GET", "POST") else 404, "Introuvable.")
        except ErreurHttp as e:
            return e.statut, {"erreur": e.message}
        except (sqlite3.Error, OSError, ValueError, json.JSONDecodeError) as e:
            log.warning("erreur de lecture : %s", type(e).__name__)
            return 503, {"erreur": "Source Hermes illisible.", "type": type(e).__name__}

    def _lecture(self, seg: list[str], q: dict) -> dict:
        n = len(seg)
        if seg == ["sante"]:
            return self.sante()
        if seg and seg[0] == "messagerie":
            if self.messagerie is None:
                raise ErreurHttp(404, "Introuvable.")
            if seg == ["messagerie"]:
                return self.messagerie.liste()
            if n == 2:
                return self.messagerie.conversation(seg[1], _entier(q, "depuis", 0, 0, 10**9))
        if seg == ["gateway"]:
            return self.gateway()
        if seg == ["vault"]:
            if self.coffre is None:
                raise ErreurHttp(404, "Introuvable.")
            return self.coffre.liste()
        if seg == ["a-decider"]:
            # Approbations en attente et questions des managers (messagerie) ; vide si la messagerie est coupée.
            return self.messagerie.a_decider() if self.messagerie is not None else {"elements": []}
        if seg == ["cron"]:
            return self.planifiees()
        if n == 2 and seg[0] == "cron":
            return self.planifiee(seg[1])
        if n == 4 and seg[0] == "cron" and seg[2] == "sorties":
            return self.sortie(seg[1], seg[3])
        if seg == ["consommation"]:
            return self.consommation(_entier(q, "jours", 30, 1, 365))
        if seg == ["consommation", "sessions"]:
            return self.sessions(_entier(q, "limite", 100, 1, 500))
        if seg == ["poles"]:
            return self.poles()
        if seg == ["erreurs"]:
            return self.erreurs(_entier(q, "heures", 24, 1, 168))
        raise ErreurHttp(404, "Introuvable.")

    # -- lectures -------------------------------------------------------------
    def sante(self) -> dict:
        verifs = []

        def verif(nom: str, fn):
            try:
                detail = fn()
                verifs.append({"nom": nom, "ok": True, "detail": detail})
            except Exception as e:  # noqa: BLE001 - chaque vérification est isolée
                verifs.append({"nom": nom, "ok": False, "detail": masquer(str(e))[:300]})

        def colonnes(db: str, table: str, cle: str):
            with ouvrir_ro(db) as c:
                presentes = {r["name"] for r in c.execute(f"PRAGMA table_info({table})")}
            manquantes = [x for x in COLONNES_ATTENDUES[cle] if x not in presentes]
            if manquantes:
                raise ValueError(f"colonnes manquantes : {', '.join(manquantes)}")
            return "schéma conforme"

        for db, nom in self._bases_etat():
            verif(f"state.db ({nom}).sessions", lambda db=db: colonnes(db, "sessions", "state.sessions"))
            verif(f"state.db ({nom}).session_model_usage",
                  lambda db=db: colonnes(db, "session_model_usage", "state.session_model_usage"))

        def jobs():
            liste = self._jobs()
            for j in liste:
                manquantes = [k for k in CLES_JOB if k not in j]
                if manquantes:
                    raise ValueError(f"clés manquantes : {', '.join(manquantes)}")
            return f"{len(liste)} tâche(s)"
        verif("cron/jobs.json", jobs)
        verif("cron/executions.db", lambda: colonnes(self._p("cron", "executions.db"), "executions", "cron.executions"))
        verif("gateway_state.json", lambda: lire_json(self._p("gateway_state.json")).get("gateway_state") or "?")
        if self.poles_chemin:
            verif("poles.yaml", lambda: f"{len(self._poles_yaml().get('profils') or {})} profil(s)")
        verif("cli hermes", lambda: "exécutable" if os.access(self.hermes_bin, os.X_OK)
              else echec("binaire introuvable"))
        alertes: list[str] = []
        if self.messagerie is not None:
            alertes = [masquer(a) for a in self.messagerie.alertes()]
            verif("messagerie", lambda: "garde-fous d'approbation actifs sur tous les interlocuteurs" if not alertes
                  else echec("; ".join(alertes)))
        return {
            "ok": all(v["ok"] for v in verifs),
            "version_pont": VERSION,
            "horodatage": dt.datetime.now(FUSEAU).isoformat(timespec="seconds"),
            "verifications": verifs,
        }

    def gateway(self) -> dict:
        chemin = self._p("gateway_state.json")
        if not os.path.isfile(chemin):
            return {"etat": "inconnu", "processus_actif": False, "plateformes": [], "profils_servis": []}
        g = lire_json(chemin)
        pid = g.get("pid")
        actif = False
        if isinstance(pid, int) and pid > 0:
            try:
                os.kill(pid, 0)
                actif = True
            except PermissionError:
                actif = True
            except OSError:
                actif = False
        plateformes = []
        for nom, p in sorted((g.get("platforms") or {}).items()):
            if isinstance(p, dict):
                plateformes.append({
                    "nom": nom, "etat": p.get("state"), "code_erreur": p.get("error_code"),
                    "message_erreur": masquer(p.get("error_message")), "attention": bool(p.get("needs_attention")),
                    "mis_a_jour_a": p.get("updated_at"), "reessai_depuis": p.get("retrying_since"),
                })
        return {
            "etat": g.get("gateway_state"),
            "processus_actif": actif,
            "agents_actifs": g.get("active_agents"),
            "mis_a_jour_a": g.get("updated_at"),
            "version": g.get("code_version"),
            "raison_arret": masquer(g.get("exit_reason")),
            "plateformes": plateformes,
            "profils_servis": [p for p in (g.get("served_profiles") or []) if isinstance(p, str)],
        }

    # cron ------------------------------------------------------------------
    def _job_public(self, j: dict) -> dict:
        planning = j.get("schedule") if isinstance(j.get("schedule"), dict) else {}
        livraison = j.get("deliver") if isinstance(j.get("deliver"), str) else None
        return {
            "id": j.get("id"), "nom": masquer(j.get("name")), "planning": j.get("schedule_display"),
            "type_planning": planning.get("kind"), "actif": bool(j.get("enabled")), "etat": j.get("state"),
            "en_pause_depuis": j.get("paused_at"), "raison_pause": masquer(j.get("paused_reason")),
            "prochaine_execution": j.get("next_run_at"), "derniere_execution": j.get("last_run_at"),
            "dernier_statut": j.get("last_status"), "derniere_erreur": masquer(j.get("last_error")),
            "erreur_livraison": masquer(j.get("last_delivery_error")),
            "echecs_consecutifs": j.get("failure_streak") or 0,
            # Plateforme seulement (pas l'identifiant du salon).
            "livraison": livraison.split(":", 1)[0] if livraison else None,
        }

    def planifiees(self) -> dict:
        return {"taches": [self._job_public(j) for j in self._jobs() if isinstance(j.get("id"), str)
                           and RE_JOB.match(j["id"])]}

    def _job(self, job_id: str) -> dict:
        segment_sur(job_id, RE_JOB)
        for j in self._jobs():
            if j.get("id") == job_id:
                return j
        raise ErreurHttp(404, "Tâche planifiée inconnue.")

    def planifiee(self, job_id: str) -> dict:
        job = self._job_public(self._job(job_id))
        historique = []
        chemin_db = self._p("cron", "executions.db")
        if os.path.isfile(chemin_db):
            with ouvrir_ro(chemin_db) as c:
                historique = [
                    {"id": r["id"], "source": r["source"], "statut": r["status"], "demarre_a": r["started_at"],
                     "termine_a": r["finished_at"], "erreur": masquer(r["error"]),
                     "livraison": r["delivery_outcome"], "prevue_a": r["scheduled_instant"]}
                    for r in c.execute("SELECT id, source, status, started_at, finished_at, error, delivery_outcome, "
                                       "scheduled_instant FROM executions WHERE job_id = ? "
                                       "ORDER BY started_at DESC LIMIT 50", (job_id,))]
        sorties = []
        dossier = self._p("cron", "output", job_id)
        if os.path.isdir(dossier) and not os.path.islink(dossier):
            for nom in sorted(os.listdir(dossier), reverse=True)[:100]:
                complet = os.path.join(dossier, nom)
                if RE_SORTIE.match(nom) and not os.path.islink(complet) and os.path.isfile(complet):
                    sorties.append({"nom": nom, "octets": os.stat(complet).st_size})
        job["historique"] = historique
        job["sorties"] = sorties
        return job

    def sortie(self, job_id: str, nom: str) -> dict:
        self._job(job_id)
        segment_sur(nom, RE_SORTIE)
        chemin = self._p("cron", "output", job_id, nom)
        if not os.path.isfile(chemin):
            raise ErreurHttp(404, "Sortie introuvable.")
        with open(chemin, "rb") as f:
            brut = f.read(SORTIE_MAX + 1)
        return {"tache": job_id, "nom": nom, "tronque": len(brut) > SORTIE_MAX,
                "contenu": masquer(brut[:SORTIE_MAX].decode("utf-8", errors="replace"))}

    # consommation ------------------------------------------------------------
    def _bases_etat(self) -> list[tuple[str, str]]:
        bases = []
        racine = self._p("state.db")
        if os.path.isfile(racine):
            bases.append((racine, "default"))
        dossier = self._p("profiles")
        if os.path.isdir(dossier):
            for nom in sorted(os.listdir(dossier)):
                chemin = os.path.join(dossier, nom, "state.db")
                if RE_PROFIL.match(nom) and not os.path.islink(os.path.join(dossier, nom)) and os.path.isfile(chemin):
                    bases.append((chemin_confine(self.home, "profiles", nom, "state.db"), nom))
        return bases

    def _lignes_usage(self, depuis: float):
        """Une ligne par (session, modèle) : session_model_usage, sinon la session elle-même."""
        for db, profil_db in self._bases_etat():
            with ouvrir_ro(db) as c:
                for r in c.execute(
                        "SELECT s.id AS sid, s.source, s.profile_name, u.model, u.input_tokens, u.output_tokens, "
                        "u.cache_read_tokens, u.cache_write_tokens, u.reasoning_tokens, u.estimated_cost_usd, "
                        "u.actual_cost_usd, u.last_seen AS quand FROM session_model_usage u "
                        "JOIN sessions s ON s.id = u.session_id WHERE u.last_seen >= ?", (depuis,)):
                    yield r, profil_db
                for r in c.execute(
                        "SELECT s.id AS sid, s.source, s.profile_name, s.model, s.input_tokens, s.output_tokens, "
                        "s.cache_read_tokens, s.cache_write_tokens, s.reasoning_tokens, s.estimated_cost_usd, "
                        "s.actual_cost_usd, COALESCE(s.last_activity_at, s.ended_at, s.started_at) AS quand "
                        "FROM sessions s WHERE COALESCE(s.last_activity_at, s.ended_at, s.started_at) >= ? "
                        "AND NOT EXISTS (SELECT 1 FROM session_model_usage u WHERE u.session_id = s.id)", (depuis,)):
                    yield r, profil_db

    def consommation(self, jours: int) -> dict:
        aujourd_hui = dt.datetime.now(FUSEAU).date()
        debut = aujourd_hui - dt.timedelta(days=jours - 1)
        depuis = dt.datetime.combine(debut, dt.time(), FUSEAU).timestamp()

        def vide():
            return {"entree": 0, "sortie": 0, "cache_lecture": 0, "cache_ecriture": 0, "raisonnement": 0,
                    "total": 0, "cout_estime_usd": 0.0, "cout_reel_usd": 0.0, "sessions": set()}

        par_jour = {(debut + dt.timedelta(days=i)).isoformat(): vide() for i in range(jours)}
        par_profil: dict[str, dict] = {}
        par_modele: dict[str, dict] = {}
        par_source: dict[str, dict] = {}
        total = vide()
        for r, profil_db in self._lignes_usage(depuis):
            jour = dt.datetime.fromtimestamp(float(r["quand"]), tz=FUSEAU).date().isoformat()
            if jour not in par_jour:
                continue
            profil = profil_db if profil_db != "default" else (r["profile_name"] or "default")
            cible = [par_jour[jour], par_profil.setdefault(profil, vide()),
                     par_modele.setdefault(r["model"] or "inconnu", vide()),
                     par_source.setdefault(r["source"] or "inconnue", vide()), total]
            valeurs = {
                "entree": r["input_tokens"] or 0, "sortie": r["output_tokens"] or 0,
                "cache_lecture": r["cache_read_tokens"] or 0, "cache_ecriture": r["cache_write_tokens"] or 0,
                "raisonnement": r["reasoning_tokens"] or 0,
            }
            for agg in cible:
                for k, v in valeurs.items():
                    agg[k] += int(v)
                agg["total"] += sum(int(v) for k, v in valeurs.items() if k != "raisonnement")
                agg["cout_estime_usd"] += float(r["estimated_cost_usd"] or 0)
                agg["cout_reel_usd"] += float(r["actual_cost_usd"] or 0)
                agg["sessions"].add(f"{profil_db}:{r['sid']}")

        def fige(agg: dict) -> dict:
            res = {k: v for k, v in agg.items() if k != "sessions"}
            res["cout_estime_usd"] = round(res["cout_estime_usd"], 4)
            res["cout_reel_usd"] = round(res["cout_reel_usd"], 4)
            res["sessions"] = len(agg["sessions"])
            return res

        def classe(d: dict, cle: str) -> list[dict]:
            return sorted(({cle: k, **fige(v)} for k, v in d.items()), key=lambda x: -x["total"])

        return {
            "periode_jours": jours, "du": debut.isoformat(), "au": aujourd_hui.isoformat(),
            "total": fige(total),
            "par_jour": [{"date": k, **fige(v)} for k, v in par_jour.items()],
            "par_profil": classe(par_profil, "profil"),
            "par_modele": classe(par_modele, "modele"),
            "par_source": classe(par_source, "source"),
            "note": "Tokens attribués au jour de dernière activité de la session (Europe/Paris). "
                    "Coût estimé : abonnement, le coût réel facturé peut être nul.",
        }

    def sessions(self, limite: int) -> dict:
        res = []
        for db, profil_db in self._bases_etat():
            with ouvrir_ro(db) as c:
                for r in c.execute(
                        "SELECT id, source, model, profile_name, title, started_at, ended_at, last_activity_at, end_reason, "
                        "message_count, tool_call_count, input_tokens, output_tokens, cache_read_tokens, "
                        "cache_write_tokens, estimated_cost_usd FROM sessions ORDER BY started_at DESC LIMIT ?",
                        (limite,)):
                    debut, fin = r["started_at"], r["ended_at"]
                    res.append({
                        "id": r["id"], "profil": profil_db if profil_db != "default" else (r["profile_name"] or "default"),
                        "source": r["source"], "modele": r["model"], "titre": tronquer(masquer(r["title"]), 200),
                        "demarre_a": iso_depuis_epoch(debut), "termine_a": iso_depuis_epoch(fin),
                        "derniere_activite": iso_depuis_epoch(r["last_activity_at"] or fin or debut),
                        "duree_secondes": int(fin - debut) if debut and fin else None,
                        "fin": r["end_reason"], "messages": r["message_count"], "outils": r["tool_call_count"],
                        "tokens": sum(int(r[k] or 0) for k in ("input_tokens", "output_tokens", "cache_read_tokens",
                                                               "cache_write_tokens")),
                        "cout_estime_usd": round(float(r["estimated_cost_usd"] or 0), 4),
                        "_tri": debut or 0,
                    })
        res.sort(key=lambda x: -x["_tri"])
        for x in res:
            del x["_tri"]
        return {"sessions": res[:limite]}

    # pôles -----------------------------------------------------------------
    def _poles_yaml(self) -> dict:
        if not self.poles_chemin:
            return {}
        with open(self.poles_chemin, encoding="utf-8") as f:
            return lire_yaml_simple(f.read())

    def _skills_installes(self, dossier: str) -> set[str]:
        noms: set[str] = set()
        if not os.path.isdir(dossier):
            return noms
        base = dossier.count(os.sep)
        for courant, sous, fichiers in os.walk(dossier, followlinks=False):
            if courant.count(os.sep) - base >= 4:
                sous[:] = []
            sous[:] = [d for d in sous if not d.startswith(".")]
            if "SKILL.md" in fichiers and not os.path.islink(os.path.join(courant, "SKILL.md")):
                nom = None
                with open(os.path.join(courant, "SKILL.md"), encoding="utf-8", errors="replace") as f:
                    for i, ligne in enumerate(f):
                        if i > 30:
                            break
                        m = re.match(r"^name:\s*[\"']?([^\s\"']+)", ligne)
                        if m:
                            nom = m.group(1)
                            break
                noms.add(nom or os.path.basename(courant))
                sous[:] = []
        return noms

    def _profil(self, nom: str) -> dict:
        if nom == "default":
            config, skills = self._p("config.yaml"), self._p("skills")
        else:
            config, skills = self._p("profiles", nom, "config.yaml"), self._p("profiles", nom, "skills")
        extrait = {"modele": None, "desactives": set()}
        if os.path.isfile(config):
            with open(config, encoding="utf-8", errors="replace") as f:
                extrait = extraire_config_profil(f.read())
        actifs = sorted(self._skills_installes(skills) - extrait["desactives"])
        return {"modele": extrait["modele"], "skills_actifs": actifs}

    def poles(self) -> dict:
        y = self._poles_yaml()
        declares = y.get("profils") or {}
        profils = []
        for nom in self.profils_existants():
            decl = declares.get(nom) if isinstance(declares.get(nom), dict) else {}
            if not y:
                decl = {"pole": nom, "role": "lead"}  # sans poles.yaml : un pôle par profil (R11)
            reel = self._profil(nom)
            profils.append({
                "profil": nom, "pole": decl.get("pole"), "role": decl.get("role"),
                "description": masquer(decl.get("description")) if isinstance(decl.get("description"), str) else None,
                "modele": reel["modele"] or decl.get("model"), "skills_actifs": reel["skills_actifs"],
            })
        poles: dict[str, dict] = {}
        for p in profils:
            if not p["pole"]:
                continue
            pole = poles.setdefault(p["pole"], {"pole": p["pole"], "lead": None, "executants": [], "auditeurs": [],
                                                "support": []})
            role = p["role"]
            if role == "lead":
                pole["lead"] = p["profil"]
            elif role == "auditeur":
                pole["auditeurs"].append(p["profil"])
            elif role == "executant":
                pole["executants"].append(p["profil"])
            else:
                pole["support"].append(p["profil"])
        communs = y.get("commun_skills") if isinstance(y.get("commun_skills"), list) else []
        return {"poles": list(poles.values()), "profils": profils, "skills_communs": communs}

    # erreurs ---------------------------------------------------------------
    _RE_LOG = re.compile(r"^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:,\d+)?\s+(ERROR|CRITICAL|WARNING)\s+(.*)$")

    def erreurs(self, heures: int) -> dict:
        seuil = time.time() - heures * 3600
        fichiers = [("default", self._p("logs", "errors.log"))]
        dossier = self._p("profiles")
        if os.path.isdir(dossier):
            for nom in sorted(os.listdir(dossier)):
                if RE_PROFIL.match(nom) and not os.path.islink(os.path.join(dossier, nom)):
                    fichiers.append((nom, os.path.join(dossier, nom, "logs", "errors.log")))
        entrees, comptes = [], {"ERROR": 0, "CRITICAL": 0, "WARNING": 0}
        for profil, chemin in fichiers:
            if not os.path.isfile(chemin) or os.path.islink(chemin):
                continue
            chemin = chemin_confine(self.home, os.path.relpath(chemin, self.home))
            if os.stat(chemin).st_mtime < seuil:
                continue
            with open(chemin, "rb") as f:
                f.seek(0, os.SEEK_END)
                taille = f.tell()
                f.seek(max(0, taille - 2 * 1024 * 1024))
                lignes = f.read().decode("utf-8", errors="replace").splitlines()
            for ligne in lignes:
                m = self._RE_LOG.match(ligne)
                if not m:
                    continue
                try:
                    quand = dt.datetime.strptime(m.group(1), "%Y-%m-%d %H:%M:%S").astimezone()
                except ValueError:
                    continue
                if quand.timestamp() < seuil:
                    continue
                niveau = m.group(2)
                comptes[niveau] += 1
                module, _, message = m.group(3).partition(": ")
                entrees.append({"quand": quand.astimezone(FUSEAU).isoformat(timespec="seconds"), "niveau": niveau,
                                "profil": profil, "module": tronquer(module, 120),
                                "message": tronquer(masquer(message or module), 500)})
        entrees.sort(key=lambda e: e["quand"], reverse=True)
        return {"heures": heures, "comptes": {"erreurs": comptes["ERROR"] + comptes["CRITICAL"],
                                              "avertissements": comptes["WARNING"]},
                "entrees": entrees[:100]}

    # -- actions --------------------------------------------------------------
    ACTIONS = ("cron.run", "cron.pause", "cron.resume")

    def commande(self, action: str, donnees: dict) -> tuple[list[str], str | None]:
        """Construit la ligne de commande `hermes` (liste, jamais de shell) après validation stricte."""
        if action in ("cron.run", "cron.pause", "cron.resume"):
            job_id = donnees.get("job_id")
            if not isinstance(job_id, str) or not RE_JOB.match(job_id):
                raise ErreurHttp(422, "Identifiant de tâche planifiée invalide.")
            if job_id not in {j.get("id") for j in self._jobs()}:
                raise ErreurHttp(422, "Tâche planifiée inconnue.")
            return [self.hermes_bin, "cron", action.split(".", 1)[1], job_id], None
        raise ErreurHttp(404, "Action inconnue.")

    def _action_messagerie(self, action: str, corps: bytes) -> tuple[int, dict]:
        if self.messagerie is None or action not in ("messagerie.envoyer", "messagerie.arreter", "messagerie.archiver"):
            raise ErreurHttp(404, "Action inconnue.")
        try:
            donnees = json.loads(corps.decode("utf-8") or "{}")
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise ErreurHttp(400, "JSON invalide.") from None
        if not isinstance(donnees, dict):
            raise ErreurHttp(400, "JSON invalide.")
        return getattr(self.messagerie, action.split(".", 1)[1])(donnees)

    def _action_coffre(self, action: str, corps: bytes) -> tuple[int, dict]:
        if self.coffre is None or action not in self.coffre.ACTIONS:
            raise ErreurHttp(404, "Action inconnue.")
        try:
            donnees = json.loads(corps.decode("utf-8") or "{}")
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise ErreurHttp(400, "JSON invalide.") from None
        if not isinstance(donnees, dict):
            raise ErreurHttp(400, "JSON invalide.")
        return self.coffre.action(action, donnees)

    def _action(self, action: str, corps: bytes) -> tuple[int, dict]:
        if action not in self.ACTIONS:
            raise ErreurHttp(404, "Action inconnue.")
        try:
            donnees = json.loads(corps.decode("utf-8") or "{}")
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise ErreurHttp(400, "JSON invalide.") from None
        if not isinstance(donnees, dict):
            raise ErreurHttp(400, "JSON invalide.")
        cmd, entree = self.commande(action, donnees)
        env = {
            "PATH": "/usr/local/bin:/usr/bin:/bin:" + os.path.dirname(self.hermes_bin),
            "HOME": os.environ.get("HOME", "/nonexistent"),
            "LANG": "C.UTF-8",
            "HERMES_HOME": self.home,
            "HERMES_QUIET": "1",
            "NO_COLOR": "1",
        }
        debut = time.monotonic()
        try:
            # stdin toujours fourni (vide si pas de corps) : la CLI n'hérite jamais du stdin du pont.
            r = subprocess.run(cmd, input=entree or "", capture_output=True, text=True, shell=False,
                               timeout=self.delai_action, env=env, cwd=self.home, check=False)
        except subprocess.TimeoutExpired:
            log.warning("action %s : délai dépassé", action)
            return 504, {"ok": False, "action": action, "code": None, "sortie": "",
                         "erreur": f"Délai de {int(self.delai_action)} s dépassé."}
        except OSError as e:
            return 503, {"ok": False, "action": action, "code": None, "sortie": "",
                         "erreur": f"CLI hermes non exécutable ({type(e).__name__})."}
        log.info("action %s : code %s en %.1f s", action, r.returncode, time.monotonic() - debut)
        return 200, {
            "ok": r.returncode == 0, "action": action, "code": r.returncode,
            "sortie": tronquer(masquer(r.stdout.strip()), 4000), "erreur": tronquer(masquer(r.stderr.strip()), 4000),
        }


def _entier(q: dict, cle: str, defaut: int, mini: int, maxi: int) -> int:
    valeurs = q.get(cle)
    if not valeurs:
        return defaut
    if not re.fullmatch(r"\d{1,4}", valeurs[0]):
        raise ErreurHttp(422, f"Paramètre « {cle} » invalide.")
    return max(mini, min(maxi, int(valeurs[0])))


# ---------------------------------------------------------------------------
# Serveur HTTP sur socket Unix
# ---------------------------------------------------------------------------
class ServeurUnix(socketserver.ThreadingMixIn, socketserver.UnixStreamServer):
    daemon_threads = True
    pont: Pont


class Gestionnaire(BaseHTTPRequestHandler):
    server_version = "hermes-os-pont/" + VERSION
    sys_version = ""
    protocol_version = "HTTP/1.1"

    def address_string(self) -> str:  # client_address est vide sur un socket Unix
        return "socket"

    def log_message(self, format, *args):  # noqa: A002 - signature imposée
        # Journal minimal : méthode, chemin sans requête, statut. Jamais d'en-tête ni de corps.
        log.info("%s", format % args)

    def log_request(self, code="-", size="-"):
        chemin = urllib.parse.urlsplit(self.path).path
        log.info("%s %s %s", self.command, chemin[:200], code)

    def _repondre(self, statut: int, donnees: dict):
        corps = json.dumps(donnees, ensure_ascii=False, separators=(",", ":")).encode()
        self.send_response(statut)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(corps)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(corps)

    def _traiter(self):
        longueur = self.headers.get("Content-Length") or "0"
        if not longueur.isdigit() or int(longueur) > CORPS_MAX:
            self._repondre(413, {"erreur": "Corps trop volumineux."})
            return
        corps = self.rfile.read(int(longueur)) if int(longueur) else b""
        statut, donnees = self.server.pont.traiter(  # type: ignore[attr-defined]
            self.command, self.path, self.headers, corps)
        self._repondre(statut, donnees)

    do_GET = _traiter
    do_POST = _traiter

    def _refuser(self):
        self._repondre(405, {"erreur": "Méthode non autorisée."})

    do_PUT = do_DELETE = do_PATCH = do_HEAD = do_OPTIONS = _refuser


def lire_secret(chemin: str) -> bytes:
    st = os.stat(chemin)
    if not stat.S_ISREG(st.st_mode):
        raise SystemExit("Le fichier secret n'est pas un fichier ordinaire.")
    if st.st_mode & 0o077:
        raise SystemExit(f"Le fichier secret doit être en 0600 (actuel : {oct(st.st_mode & 0o777)}).")
    with open(chemin, "rb") as f:
        secret = f.read().strip()
    if len(secret) < 32:
        raise SystemExit("Secret partagé trop court (32 caractères minimum).")
    return secret


def creer_serveur(pont: Pont, socket_chemin: str) -> ServeurUnix:
    dossier = os.path.dirname(socket_chemin) or "."
    os.makedirs(dossier, mode=0o750, exist_ok=True)
    if os.path.lexists(socket_chemin):
        if not stat.S_ISSOCK(os.lstat(socket_chemin).st_mode):
            raise SystemExit(f"{socket_chemin} existe et n'est pas un socket : refus de l'écraser.")
        os.unlink(socket_chemin)
    ancien = os.umask(0o117)  # socket créé directement en 0660
    try:
        serveur = ServeurUnix(socket_chemin, Gestionnaire)
    finally:
        os.umask(ancien)
    os.chmod(socket_chemin, 0o660)
    serveur.pont = pont
    return serveur


COMMANDES = ("socket", "distant", "relier", "delier", "etat")


def _arguments(argv: list[str] | None) -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="hermes-os-pont : relie Hermes à la console hermes-os (liste blanche, aucune écriture directe).",
        epilog="Sans commande : mode socket (même machine). Distant : relier <url>, puis distant (service systemd).")
    p.add_argument("commande", nargs="?", default="socket", choices=COMMANDES)
    p.add_argument("url", nargs="?", help="relier : URL de la console (https://...)")
    p.add_argument("--hermes-home", default=os.environ.get("HERMES_OS_PONT_HERMES_HOME")
                   or os.environ.get("HERMES_HOME") or os.path.expanduser("~/.hermes"))
    p.add_argument("--poles", default=os.environ.get("HERMES_OS_POLES", ""),
                   help="poles.yaml facultatif (sinon un pôle par profil)")
    p.add_argument("--socket", default=os.environ.get("HERMES_OS_PONT_SOCKET", os.path.expanduser("~/.local/state/hermes-os/run/pont.sock")))
    p.add_argument("--secret-file", default=os.environ.get(
        "HERMES_OS_PONT_SECRET_FILE", os.path.expanduser("~/.config/hermes-os-pont/secret")))
    p.add_argument("--hermes-bin", default=os.environ.get("HERMES_OS_PONT_HERMES_BIN", "")
                   or shutil.which("hermes") or os.path.expanduser("~/.local/bin/hermes"))
    # 90 s : la CLI met ~6 s à démarrer, jusqu'à 30 s et plus quand le serveur est chargé (build en cours).
    p.add_argument("--delai-action", type=float, default=90.0)
    p.add_argument("--messagerie", action="store_true", help="active la messagerie avec les managers (ADR 0008)")
    p.add_argument("--messagerie-dossier", default=os.path.expanduser("~/.local/state/hermes-os/messagerie"))
    p.add_argument("--interlocuteurs", default="", help="liste blanche séparée par des virgules (défaut : tous les leads)")
    p.add_argument("--alerte-cout-jour", type=float, default=0.0, help="seuil de jetons par jour (0 = désactivée)")
    p.add_argument("--sans-coffre", action="store_true", help="désactive la gestion du coffre (vault) de Hermes")
    p.add_argument("--hermes-python", default="", help="Python de Hermes (défaut : hermes --print-runtime-command)")
    p.add_argument("--hermes-agent", default="", help="racine du code de Hermes (même défaut)")
    p.add_argument("--code", default="", help="relier : code d'appairage (sinon lu sur l'entrée standard)")
    p.add_argument("--nom", default="", help="relier : nom affiché dans la console (défaut : nom d'hôte)")
    p.add_argument("--sans-systemd", action="store_true", help="relier : n'installe pas le service systemd")
    p.add_argument("--intervalle", type=float, default=float(os.environ.get("HERMES_OS_PONT_INTERVALLE") or 15))
    p.add_argument("-v", "--verbeux", action="store_true")
    args = p.parse_args(argv)
    if args.commande == "relier" and not args.url:
        p.error("relier : URL de la console manquante (relier https://console.exemple.fr).")
    return args


def _construire_pont(args: argparse.Namespace, secret: bytes | None) -> Pont:
    pont = Pont(args.hermes_home, secret, args.hermes_bin, args.delai_action, poles_chemin=args.poles or None)
    sys.modules.setdefault("hermes_os_pont", sys.modules[__name__])  # une seule copie du module
    if not args.sans_coffre:
        from hermes_os_coffre import Coffre
        pont.coffre = Coffre(pont, args.hermes_python, args.hermes_agent)
    if args.messagerie:
        from hermes_os_messagerie import Messagerie
        liste = [x.strip() for x in args.interlocuteurs.split(",") if x.strip()]
        pont.messagerie = Messagerie(pont, args.messagerie_dossier, liste or None,
                                     alerte_cout_jour=args.alerte_cout_jour or None)
    return pont


def _socket(args: argparse.Namespace) -> int:
    pont = _construire_pont(args, lire_secret(args.secret_file))
    serveur = creer_serveur(pont, args.socket)
    log.warning("hermes-os-pont %s à l'écoute sur %s (Hermes : %s)", VERSION, args.socket, pont.home)
    try:
        serveur.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        serveur.server_close()
        if os.path.exists(args.socket):
            os.unlink(args.socket)
    return 0


def _options_service(args: argparse.Namespace) -> list[str]:
    """Options de `relier` reprises telles quelles dans le service systemd `distant`."""
    opts: list[str] = []
    if args.poles:
        opts += ["--poles", os.path.abspath(args.poles)]
    if args.hermes_bin:
        opts += ["--hermes-bin", args.hermes_bin]
    if args.messagerie:
        opts += ["--messagerie"]
        if args.interlocuteurs:
            opts += ["--interlocuteurs", args.interlocuteurs]
    if args.sans_coffre:
        opts += ["--sans-coffre"]
    return opts


def _distant(args: argparse.Namespace) -> int:
    import hermes_os_distant as hd
    sys.modules.setdefault("hermes_os_pont", sys.modules[__name__])
    home = os.path.realpath(args.hermes_home)
    try:
        if args.commande == "etat":
            print(json.dumps(hd.etat(home), ensure_ascii=False, indent=2))
            return 0
        if args.commande == "delier":
            faits = hd.delier(home)
            print("Ce Hermes est délié : " + (", ".join(faits) if faits else "rien à retirer") + ".")
            print("Pensez à cliquer « Délier » dans Compte si la console le montre encore relié.")
            return 0
        if args.commande == "relier":
            # Avant l'appairage : le code n'est pas consommé si un pont du même nom tourne déjà (risque qa L5).
            if not args.sans_systemd and hd.service_actif():
                raise hd.ErreurDistant(hd.message_service_actif(__file__, home))
            code = args.code
            if not code:
                if sys.stdin.isatty():
                    print("Code d'appairage (affiché dans Compte, valable 10 min) : ", end="", flush=True)
                code = sys.stdin.readline()
            pont = _construire_pont(args, None)
            nom = args.nom or socket.gethostname()
            r = hd.relier(args.url, code, nom, home, pont.profils_existants(), VERSION)
            print(f"Hermes « {nom} » relié à {r['url']}.")
            if args.sans_systemd:
                print(f"Lancez le pont : {sys.executable} {os.path.abspath(__file__)} distant")
            else:
                chemin = hd.installer_systemd(__file__, home, _options_service(args))
                print(f"Service installé et démarré ({chemin}).")
            return 0
        inst = hd.lire_instance(home)
        pont = _construire_pont(args, None)
        console = hd.Console(inst["url"], inst["instance_id"], inst["secret"])
        log.warning("hermes-os-pont %s relié à %s (Hermes : %s)", VERSION, inst["url"], pont.home)
        return hd.Boucle(pont, console, inst.get("nom") or socket.gethostname(), VERSION, args.intervalle).tourner()
    except hd.ErreurDistant as e:
        print(f"hermes-os-pont : {e}", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        return 0


def main(argv: list[str] | None = None) -> int:
    args = _arguments(argv)
    logging.basicConfig(level=logging.INFO if args.verbeux else logging.WARNING,
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s", stream=sys.stderr)
    if args.commande == "socket":
        return _socket(args)
    return _distant(args)


if __name__ == "__main__":
    sys.exit(main())
