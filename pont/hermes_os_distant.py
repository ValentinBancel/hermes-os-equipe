"""Mode distant du pont hermes-os : relie un Hermes à une console hébergée ailleurs (lot L4, R2-R12).

- Aucun port ouvert : le pont appelle la console en HTTPS sortant (HTTP refusé hors bouclage, sauf
  HERMES_OS_PONT_HTTP_NON_SUR=1 réservé aux tests).
- Appairage : `relier <url>` échange un code à usage unique (affiché dans Compte) contre un identifiant
  d'instance et un secret de 32 octets, rangés dans $HERMES_HOME/hermes-os-pont/instance.json (0700/0600).
- Boucle `distant` : pousse les relevés (lectures en liste blanche du pont local) toutes les 15 s et après
  chaque action ; récupère les actions par long-poll et renvoie leur résultat. Une 401 (instance déliée)
  arrête la boucle proprement.
- Chaque requête est signée : X-Hermes-Instance, X-Hermes-Horodatage, X-Hermes-Nonce (32 hex),
  X-Hermes-Signature = hex(HMAC-SHA256(secret, MÉTHODE\\nchemin?requête\\nhorodatage\\nnonce\\nhex(sha256(corps)))).
  La clé HMAC est le secret tel qu'il est transmis (texte base64url).

Python 3.11+, stdlib uniquement.
"""
from __future__ import annotations

import datetime as dt
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import re
import secrets
import shutil
import socket
import stat
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Mapping
from zoneinfo import ZoneInfo

log = logging.getLogger("hermes-os-pont")

RELEVES_MAX = 2 * 1024 * 1024  # R10 : la console refuse au-delà (413)
ATTENTE_ACTIONS = 25           # R10 : long-poll console ≤ 25 s
ALPHABET_CODE = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"
RE_CODE = re.compile(rf"^[{ALPHABET_CODE}]{{8}}$")
RE_INSTANCE = re.compile(r"^[A-Za-z0-9-]{8,64}$")
RE_SECRET = re.compile(r"^[A-Za-z0-9_-]{43,128}$")
RE_ACTION = re.compile(r"^[a-z]{1,20}\.[a-z_]{1,30}$")
RE_LECTURE = re.compile(r"^/v1/[A-Za-z0-9._/%-]{1,300}(\?[A-Za-z0-9._=&%-]{0,300})?$")
SERVICE = "hermes-os-pont.service"


class ErreurDistant(Exception):
    """Erreur affichable telle quelle (jamais de secret dans le message)."""


class InstanceRevoquee(ErreurDistant):
    pass


# ---------------------------------------------------------------------------
# Validation
# ---------------------------------------------------------------------------
def normaliser_code(brut: str) -> str:
    code = re.sub(r"[\s-]", "", brut or "").upper()
    if not RE_CODE.match(code):
        raise ErreurDistant("Code d'appairage invalide : 8 caractères, au format XXXX-XXXX.")
    return code


def _bouclage(hote: str) -> bool:
    if hote == "localhost":
        return True
    try:
        return ipaddress.ip_address(hote).is_loopback
    except ValueError:
        return False


def url_console(brut: str, environ: Mapping[str, str] | None = None) -> str:
    """URL de base de la console, sans barre finale. HTTPS exigé, HTTP seulement en bouclage (R3)."""
    environ = os.environ if environ is None else environ
    u = urllib.parse.urlsplit((brut or "").strip())
    if u.scheme not in ("https", "http") or not u.hostname or u.username or u.password or u.query or u.fragment:
        raise ErreurDistant("URL de console invalide (attendu : https://console.exemple.fr).")
    if u.scheme == "http" and not _bouclage(u.hostname):
        if environ.get("HERMES_OS_PONT_HTTP_NON_SUR") != "1":
            raise ErreurDistant("HTTP refusé : la console doit être en HTTPS (HTTP admis uniquement en local).")
        log.warning("HERMES_OS_PONT_HTTP_NON_SUR=1 : connexion HTTP non chiffrée vers %s (tests uniquement).",
                    u.hostname)
    return urllib.parse.urlunsplit((u.scheme, u.netloc, u.path.rstrip("/"), "", ""))


# ---------------------------------------------------------------------------
# Fichier d'instance (R6)
# ---------------------------------------------------------------------------
def dossier_instance(home: str) -> str:
    return os.path.join(home, "hermes-os-pont")


def chemin_instance(home: str) -> str:
    return os.path.join(dossier_instance(home), "instance.json")


def ecrire_instance(home: str, donnees: dict) -> str:
    dossier = dossier_instance(home)
    os.makedirs(dossier, mode=0o700, exist_ok=True)
    if os.path.islink(dossier):
        raise ErreurDistant(f"{dossier} est un lien symbolique : refus.")
    os.chmod(dossier, 0o700)
    chemin = chemin_instance(home)
    temporaire = chemin + ".tmp"
    with contextlib_suppress_file(temporaire):
        fd = os.open(temporaire, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            os.fchmod(f.fileno(), 0o600)
            json.dump(donnees, f, ensure_ascii=False, indent=2)
        os.replace(temporaire, chemin)
    return chemin


class contextlib_suppress_file:  # noqa: N801 - petit gestionnaire local
    """Supprime le fichier temporaire si l'écriture échoue."""

    def __init__(self, chemin: str):
        self.chemin = chemin

    def __enter__(self):
        return self

    def __exit__(self, typ, *_):
        if typ is not None and os.path.lexists(self.chemin):
            os.unlink(self.chemin)
        return False


def lire_instance(home: str) -> dict:
    dossier, chemin = dossier_instance(home), chemin_instance(home)
    if not os.path.lexists(chemin):
        raise ErreurDistant(f"Ce Hermes n'est relié à aucune console : {chemin} est absent (lancez : relier <url>, "
                            "ou précisez --hermes-home si le home Hermes est ailleurs).")
    for c, attendu in ((dossier, stat.S_ISDIR), (chemin, stat.S_ISREG)):
        st = os.lstat(c)
        if not attendu(st.st_mode):
            raise ErreurDistant(f"{c} n'est pas du type attendu : refus de démarrer.")
        if st.st_mode & 0o077:
            raise ErreurDistant(f"{c} est trop ouvert ({oct(st.st_mode & 0o777)}) : 0700 pour le dossier, "
                                "0600 pour le fichier, sinon refus de démarrer.")
    with open(chemin, encoding="utf-8") as f:
        donnees = json.load(f)
    if not isinstance(donnees, dict) or not RE_INSTANCE.match(str(donnees.get("instance_id", ""))) \
            or not RE_SECRET.match(str(donnees.get("secret", ""))):
        raise ErreurDistant(f"{chemin} est illisible : reliez à nouveau ce Hermes.")
    donnees["url"] = url_console(str(donnees.get("url", "")))
    return donnees


# ---------------------------------------------------------------------------
# Client HTTP signé
# ---------------------------------------------------------------------------
def signer(secret: str, methode: str, cible: str, horodatage: str, nonce: str, corps: bytes) -> str:
    message = "\n".join([methode.upper(), cible, horodatage, nonce, hashlib.sha256(corps).hexdigest()])
    return hmac.new(secret.encode(), message.encode(), hashlib.sha256).hexdigest()


class _SansRedirection(urllib.request.HTTPRedirectHandler):
    """Une redirection n'est jamais suivie (secu m2) : la requête signée ne part que vers l'URL reliée."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D102 - API urllib
        return None


_OUVREUR = urllib.request.build_opener(_SansRedirection)

# Une action plus vieille que ça n'est plus attendue par la console (même délai que son expiration) : elle n'est pas exécutée.
ACTION_AGE_MAX = 90


class Console:
    """Appels vers la console. `ouvrir` est injectable pour les tests."""

    def __init__(self, url: str, instance_id: str | None = None, secret: str | None = None,
                 ouvrir=None, horloge=time.time):
        self.url = url
        self.instance_id = instance_id
        self.secret = secret
        self.ouvrir = ouvrir or _OUVREUR.open
        self.horloge = horloge

    def requete(self, methode: str, cible: str, donnees: dict | None = None, delai: float = 30.0,
                signee: bool = True) -> tuple[int, dict | None]:
        corps = b"" if donnees is None else json.dumps(donnees, ensure_ascii=False,
                                                       separators=(",", ":")).encode()
        entetes = {"Accept": "application/json", "User-Agent": "hermes-os-pont"}
        if donnees is not None:
            entetes["Content-Type"] = "application/json"
        if signee:
            horodatage, nonce = str(int(self.horloge())), secrets.token_hex(16)
            entetes.update({
                "X-Hermes-Instance": self.instance_id or "",
                "X-Hermes-Horodatage": horodatage,
                "X-Hermes-Nonce": nonce,
                "X-Hermes-Signature": signer(self.secret or "", methode, cible, horodatage, nonce, corps),
            })
        req = urllib.request.Request(self.url + cible, data=corps if methode == "POST" else None,
                                     headers=entetes, method=methode)
        try:
            with self.ouvrir(req, timeout=delai) as r:
                statut, brut = r.status, r.read(RELEVES_MAX)
        except urllib.error.HTTPError as e:
            statut, brut = e.code, e.read(64 * 1024) if e.fp else b""
        except (urllib.error.URLError, OSError) as e:
            raise ErreurDistant(f"Console injoignable ({type(e).__name__}).") from None
        if statut == 401 and signee:
            raise InstanceRevoquee("La console refuse ce Hermes (instance déliée ou horloge décalée).")
        try:
            json_ = json.loads(brut) if brut else None
        except ValueError:
            json_ = None
        return statut, json_ if isinstance(json_, dict) else None


# ---------------------------------------------------------------------------
# relier / delier / etat
# ---------------------------------------------------------------------------
def relier(url: str, code: str, nom: str, home: str, profils: list[str], version: str,
           ouvrir=None) -> dict:
    base = url_console(url)
    code = normaliser_code(code)
    console = Console(base, ouvrir=ouvrir)
    statut, rep = console.requete("POST", "/pont/v1/appairage", {
        "code": code, "nom": nom[:100], "hote": socket.gethostname()[:255], "version": version,
        "profils": profils[:200],
    }, delai=20, signee=False)
    if statut == 429:
        raise ErreurDistant("Trop d'essais : patientez une minute.")
    if statut != 201 or not rep:
        raise ErreurDistant("Code refusé : il est faux, expiré ou déjà utilisé. Générez-en un nouveau dans Compte.")
    instance_id, secret = str(rep.get("instance_id", "")), str(rep.get("secret", ""))
    if not RE_INSTANCE.match(instance_id) or not RE_SECRET.match(secret):
        raise ErreurDistant("Réponse inattendue de la console.")
    ecrire_instance(home, {
        "url": base, "instance_id": instance_id, "secret": secret, "nom": nom[:100],
        "relie_le": dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds"),
    })
    return {"url": base, "instance_id": instance_id}


def unite_systemd(script: str, home: str, options: list[str]) -> str:
    def q(x: str) -> str:
        # systemd développe `$VAR` et les spécificateurs `%x` même entre guillemets (secu m7) : on les double.
        x = x.replace("\\", "\\\\").replace('"', '\\"').replace("$", "$$").replace("%", "%%")
        return '"' + x + '"'
    commande = " ".join(q(x) for x in [sys.executable or "python3", os.path.abspath(script), "distant", *options])
    return (
        "[Unit]\nDescription=hermes-os-pont (mode distant)\nAfter=network-online.target\n"
        "Wants=network-online.target\n\n[Service]\nType=simple\n"
        f"Environment={q('HERMES_HOME=' + home)}\nExecStart={commande}\n"
        "Restart=on-failure\nRestartSec=10\n# Code 0 = instance déliée : pas de redémarrage.\n"
        "RestartPreventExitStatus=0\nNoNewPrivileges=yes\n\n[Install]\nWantedBy=default.target\n"
    )


def chemin_unite() -> str:
    base = os.environ.get("XDG_CONFIG_HOME") or os.path.expanduser("~/.config")
    return os.path.join(base, "systemd", "user", SERVICE)


def service_actif(executer=None) -> bool:
    """Une unité `hermes-os-pont.service` tourne-t-elle déjà pour cet utilisateur (pont du même nom) ?"""
    try:
        r = (executer or subprocess.run)(["systemctl", "--user", "is-active", "--quiet", SERVICE],
                                         capture_output=True, text=True, check=False)
    except OSError:
        return False
    return r.returncode == 0


def message_service_actif(script: str, home: str) -> str:
    return (f"{SERVICE} est déjà active (un pont tourne déjà sous ce nom) : rien n'a été modifié. "
            f"Pour la remplacer : « systemctl --user disable --now {SERVICE} », puis relancez « relier ». "
            "Pour la garder : relancez « relier » avec --sans-systemd, puis lancez ce pont à la main : "
            f"« {sys.executable} {os.path.abspath(script)} distant --hermes-home {home} ».")


def installer_systemd(script: str, home: str, options: list[str], executer=None) -> str:
    executer = executer or subprocess.run  # résolu à l'appel : les tests remplacent subprocess.run
    if service_actif(executer):
        # Nom d'unité fixe : la réécrire ou l'« enable --now » ne relancerait pas le pont déjà en marche (risque qa L5).
        raise ErreurDistant(message_service_actif(script, home))
    chemin = chemin_unite()
    os.makedirs(os.path.dirname(chemin), exist_ok=True)
    with open(chemin, "w", encoding="utf-8") as f:
        f.write(unite_systemd(script, home, options))
    for argv in (["systemctl", "--user", "daemon-reload"], ["systemctl", "--user", "enable", "--now", SERVICE]):
        r = executer(argv, capture_output=True, text=True, check=False)
        if r.returncode != 0:
            raise ErreurDistant(f"systemctl a échoué ({' '.join(argv[2:])}) : lancez le pont à la main "
                                f"avec « {os.path.abspath(script)} distant ».")
    return chemin


def delier(home: str, executer=subprocess.run) -> list[str]:
    faits: list[str] = []
    if os.path.isfile(chemin_unite()) and shutil.which("systemctl"):
        executer(["systemctl", "--user", "disable", "--now", SERVICE], capture_output=True, text=True, check=False)
        os.unlink(chemin_unite())
        faits.append("service systemd retiré")
    if os.path.lexists(chemin_instance(home)):
        os.unlink(chemin_instance(home))
        faits.append("identifiants d'instance supprimés")
    return faits


def etat(home: str) -> dict:
    try:
        inst = lire_instance(home)
    except ErreurDistant as e:
        return {"relie": False, "message": str(e)}
    return {"relie": True, "url": inst["url"], "instance_id": inst["instance_id"], "nom": inst.get("nom"),
            "relie_le": inst.get("relie_le"), "service": os.path.isfile(chemin_unite())}


# ---------------------------------------------------------------------------
# Boucle distante
# ---------------------------------------------------------------------------
LECTURES_FIXES = ["/v1/sante", "/v1/gateway", "/v1/a-decider", "/v1/cron", "/v1/consommation?jours=7",
                  "/v1/consommation?jours=30", "/v1/consommation/sessions?limite=100", "/v1/poles",
                  "/v1/erreurs?heures=24"]


def trop_vieille(cree_le, maintenant: dt.datetime | None = None) -> bool:
    """Vrai si l'action a plus de ACTION_AGE_MAX s (secu m4) ; une date absente ou illisible n'arrête rien."""
    if not isinstance(cree_le, str):
        return False
    try:
        cree = dt.datetime.fromisoformat(cree_le)
    except ValueError:
        return False
    if cree.tzinfo is None:
        cree = cree.replace(tzinfo=dt.timezone.utc)
    maintenant = maintenant or dt.datetime.now(dt.timezone.utc)
    return (maintenant - cree).total_seconds() > ACTION_AGE_MAX


class Boucle:
    def __init__(self, pont, console: Console, nom: str, version: str, intervalle: float = 15.0):
        self.pont = pont
        self.console = console
        self.nom = nom
        self.version = version
        self.intervalle = max(1.0, intervalle)
        self.arret = threading.Event()
        self.verrou = threading.Lock()
        self.latence_ms: int | None = None
        self.revoquee = False

    # relevés ----------------------------------------------------------------
    def cles(self) -> list[str]:
        cles = list(LECTURES_FIXES)
        jour = dt.datetime.now(ZoneInfo("Europe/Paris")).day
        if f"/v1/consommation?jours={jour}" not in cles:
            cles.append(f"/v1/consommation?jours={jour}")
        if getattr(self.pont, "coffre", None) is not None:
            cles.append("/v1/vault")
        if getattr(self.pont, "messagerie", None) is not None:
            cles.append("/v1/messagerie")
        return cles

    def releves(self) -> dict:
        res: dict[str, dict] = {}
        for cle in self.cles():
            statut, d = self.pont.executer("GET", cle)
            res[cle] = {"statut": statut, "donnees": d}
            if cle == "/v1/cron" and statut == 200:
                for job in d.get("taches") or d.get("jobs") or []:
                    jid = job.get("id") if isinstance(job, dict) else None
                    if isinstance(jid, str) and re.fullmatch(r"[a-f0-9]{12}", jid):
                        s2, d2 = self.pont.executer("GET", f"/v1/cron/{jid}")
                        res[f"/v1/cron/{jid}"] = {"statut": s2, "donnees": d2}
        return res

    def corps_releves(self) -> dict:
        releves = self.releves()
        corps = {"nom": self.nom, "hote": socket.gethostname()[:255], "version": self.version,
                 "profils": self.pont.profils_existants(), "latence_ms": self.latence_ms, "releves": releves}
        # Sous la limite de 2 Mo : retire d'abord les détails de tâches, puis les plus gros relevés.
        while len(json.dumps(corps, ensure_ascii=False).encode()) > RELEVES_MAX - 1024 and releves:
            cle = max(releves, key=lambda k: (k.startswith("/v1/cron/"), len(json.dumps(releves[k]))))
            log.warning("relevé %s retiré (taille totale > 2 Mo)", cle)
            del releves[cle]
        return corps

    def pousser(self) -> None:
        with self.verrou:
            corps = self.corps_releves()
            debut = time.monotonic()
            statut, _ = self.console.requete("POST", "/pont/v1/releves", corps, delai=30)
            self.latence_ms = int((time.monotonic() - debut) * 1000)
            if statut not in (200, 204):
                log.warning("relevés refusés par la console (%s)", statut)

    # actions ----------------------------------------------------------------
    def executer_action(self, a: dict) -> dict:
        if trop_vieille(a.get("cree_le")):
            return {"ok": False, "code": 408, "message": "Action expirée."}
        nom, donnees = str(a.get("action", "")), a.get("donnees")
        donnees = donnees if isinstance(donnees, dict) else {}
        acteur = a.get("acteur") if isinstance(a.get("acteur"), str) else None
        if nom == "lecture":
            chemin = donnees.get("chemin")
            if not isinstance(chemin, str) or not RE_LECTURE.match(chemin) or ".." in chemin:
                return {"ok": False, "code": 404, "message": "Lecture refusée."}
            statut, d = self.pont.executer("GET", chemin)
            return {"ok": statut == 200, "code": statut, "message": str(d.get("erreur", "")), "donnees": d}
        if not RE_ACTION.match(nom):
            return {"ok": False, "code": 404, "message": "Action inconnue."}
        corps = dict(donnees)
        if acteur:
            corps["acteur"] = acteur  # C7 : le pont journalise l'acteur (hp.acteur valide le format)
        statut, d = self.pont.executer("POST", f"/v1/actions/{nom}",
                                       json.dumps(corps, ensure_ascii=False).encode())
        if "ok" in d:
            return {"ok": d.get("ok") is True, "code": d.get("code"), "message": str(d.get("erreur") or ""),
                    "donnees": d}
        return {"ok": 200 <= statut < 300, "code": statut, "message": str(d.get("erreur", "")), "donnees": d}

    def traiter(self, actions: list) -> None:
        for a in actions:
            if not isinstance(a, dict) or not isinstance(a.get("id"), (int, str)):
                continue
            ident = str(a["id"])
            if not re.fullmatch(r"[A-Za-z0-9-]{1,64}", ident):
                continue
            try:
                resultat = self.executer_action(a)
            except Exception as e:  # noqa: BLE001 - une action ne doit jamais tuer la boucle
                log.warning("action %s : %s", a.get("action"), type(e).__name__)
                resultat = {"ok": False, "code": 500, "message": "Erreur du pont."}
            self.console.requete("POST", f"/pont/v1/actions/{ident}/resultat", resultat, delai=30)
        if actions:
            self.pousser()

    def _releves_en_boucle(self) -> None:
        while not self.arret.wait(self.intervalle):
            try:
                self.pousser()
            except InstanceRevoquee:
                self._revoquer()
            except ErreurDistant as e:
                log.warning("%s", e)

    def _revoquer(self) -> None:
        self.revoquee = True
        self.arret.set()

    def tourner(self, tours: int | None = None) -> int:
        """Tourne jusqu'à l'arrêt (ou `tours` long-polls pour les tests). 0 si l'instance a été déliée."""
        try:
            self.pousser()
        except InstanceRevoquee:
            self._revoquer()
        except ErreurDistant as e:
            log.warning("%s", e)
        fil = threading.Thread(target=self._releves_en_boucle, daemon=True)
        fil.start()
        attente = 1.0
        n = 0
        while not self.arret.is_set() and (tours is None or n < tours):
            n += 1
            try:
                statut, rep = self.console.requete("GET", f"/pont/v1/actions?attente={ATTENTE_ACTIONS}",
                                                   delai=ATTENTE_ACTIONS + 10)
                attente = 1.0
                if statut == 200 and rep and isinstance(rep.get("actions"), list):
                    self.traiter(rep["actions"])
                elif statut not in (200, 204):
                    self.arret.wait(5)
            except InstanceRevoquee:
                self._revoquer()
            except ErreurDistant as e:
                log.warning("%s (nouvel essai dans %.0f s)", e, attente)
                self.arret.wait(attente)
                attente = min(attente * 2, 60.0)
        self.arret.set()
        fil.join(timeout=5)
        if self.revoquee:
            log.warning("Ce Hermes a été délié de la console : arrêt du pont. Pour le relier à nouveau : relier <url>.")
            return 0
        return 0
