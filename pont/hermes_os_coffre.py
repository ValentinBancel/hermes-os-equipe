"""Coffre (« vault ») de Hermes pour la console hermes-os : liste, création, modification, suppression.

Le pont reste en stdlib : il valide tout ici, puis délègue à `hermes_os_coffre_agent.py`, exécuté par
l'interpréteur Python de Hermes (le coffre est chiffré avec `cryptography`, et seul `agent.vault_store` sait le
lire et l'écrire sous verrou). La CLI `hermes vault add` est interactive (getpass) : pas de commande pilotable.

- Les valeurs secrètes (mot de passe, clé 2FA, carte, adresse) passent par l'entrée standard du sous-processus,
  jamais par argv, ni dans le journal du pont, ni dans une réponse. Le pont ne relit jamais un secret.
- Un profil = un coffre : `<HERMES_HOME>/vault` (default) ou `<HERMES_HOME>/profiles/<profil>/vault`.
- Modifier = supprimer l'ancien élément puis créer le nouveau (Hermes ne sait pas modifier) : la poignée change.
"""
from __future__ import annotations

import json
import logging
import os
import re
import subprocess
import time

log = logging.getLogger("hermes-os-pont")

ICI = os.path.dirname(os.path.abspath(__file__))
SCRIPT_AGENT = os.path.join(ICI, "hermes_os_coffre_agent.py")

RE_POIGNEE = re.compile(r"^vault_[a-f0-9]{12}$")
RE_ORIGINE = re.compile(r"^https?://[^\s/?#@]+(?::\d{1,5})?/?$")
TYPES = ("login", "payment", "address")
TYPES_IDENTIFIANT = ("email", "phone", "username")
CHAMPS_SECRETS = {
    "login": ("password", "otp_secret"),
    "payment": ("card_number", "cardholder_name", "exp_month", "exp_year", "cvc", "billing_postal_code"),
    "address": ("address_line1", "address_line2", "city", "state", "postal_code", "country"),
}
OBLIGATOIRES = {
    "login": ("password",),
    "payment": ("card_number", "exp_month", "exp_year", "cvc"),
    "address": ("address_line1", "city", "postal_code", "country"),
}
SECRET_MAX = 1024
LISTE_TTL = 3.0


class Coffre:
    ACTIONS = ("vault.create", "vault.update", "vault.delete")

    def __init__(self, pont, python: str = "", racine_agent: str = "", script: str = SCRIPT_AGENT, delai: float = 30.0):
        self.pont = pont
        self._python = python
        self._racine = racine_agent
        self.script = script
        self.delai = delai
        self._cache: tuple[float, dict] | None = None

    # -- interpréteur de Hermes ----------------------------------------------
    def _interpreteur(self) -> tuple[str, str]:
        """Python et racine de Hermes : options du pont, sinon `hermes --print-runtime-command` (relu à chaque
        appel tant qu'il échoue : une mise à jour de Hermes déplace l'interpréteur)."""
        if self._python and self._racine:
            return self._python, self._racine
        try:
            r = subprocess.run([self.pont.hermes_bin, "--print-runtime-command"], capture_output=True, text=True,
                               timeout=20, check=False, stdin=subprocess.DEVNULL, env=self._env(self.pont.home))
            cmd = json.loads(r.stdout)
            python = self._python or cmd[0]
            m = re.search(r"sys\.path\.insert\(0, '([^']+)'\)", cmd[-1])
            racine = self._racine or (m.group(1) if m else "")
        except (OSError, ValueError, IndexError, TypeError, subprocess.SubprocessError):
            raise self._erreur(503, "Interpréteur de Hermes introuvable.") from None
        if not os.path.isfile(python) or not os.path.isdir(racine):
            raise self._erreur(503, "Interpréteur de Hermes introuvable.")
        self._python, self._racine = python, racine
        return python, racine

    @staticmethod
    def _erreur(statut: int, message: str):
        from hermes_os_pont import ErreurHttp
        return ErreurHttp(statut, message)

    @staticmethod
    def _env(hermes_home: str) -> dict:
        return {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": os.environ.get("HOME", "/nonexistent"),
                "LANG": "C.UTF-8", "HERMES_HOME": hermes_home, "HERMES_QUIET": "1", "NO_COLOR": "1",
                "PYTHONDONTWRITEBYTECODE": "1"}

    def _home_profil(self, profil: object) -> str:
        if not isinstance(profil, str) or profil not in self.pont.profils_existants():
            raise self._erreur(422, "Profil inconnu.")
        if profil == "default":
            return self.pont.home
        return os.path.join(self.pont.home, "profiles", profil)

    def _executer(self, requete: dict, hermes_home: str) -> tuple[int, dict]:
        python, racine = self._interpreteur()
        # HERMES_HOME reste la racine même pour le coffre d'un profil (le dossier est passé dans la requête) :
        # Hermes y cherche son environnement Python, et l'installerait ailleurs à chaque appel.
        env = self._env(self.pont.home) | {"HERMES_OS_AGENT_ROOT": racine}
        try:
            r = subprocess.run([python, "-I", self.script], input=json.dumps(requete), capture_output=True, text=True,
                               shell=False, timeout=self.delai, env=env, cwd=self.pont.home, check=False)
        except subprocess.TimeoutExpired:
            return 504, {"erreur": f"Délai de {int(self.delai)} s dépassé."}
        except OSError as e:
            return 503, {"erreur": f"Interpréteur de Hermes non exécutable ({type(e).__name__})."}
        try:
            res = json.loads(r.stdout.strip().splitlines()[-1])
            if not isinstance(res, dict):
                raise ValueError
        except (ValueError, IndexError):
            # stderr n'est jamais renvoyé : une trace Python pourrait citer une valeur.
            log.warning("coffre : réponse illisible (code %s)", r.returncode)
            return 200, {"erreur": "Réponse illisible du coffre de Hermes."}
        return 200, res

    # -- lecture ----------------------------------------------------------------
    def liste(self) -> dict:
        maintenant = time.monotonic()
        if self._cache and maintenant - self._cache[0] < LISTE_TTL:
            return self._cache[1]
        profils = self.pont.profils_existants()
        dossiers = {p: os.path.join(self._home_profil(p), "vault") for p in profils}
        statut, res = self._executer({"op": "list", "dossiers": dossiers}, self.pont.home)
        if "erreur" in res:
            raise self._erreur(503 if statut == 200 else statut, str(res["erreur"]))
        elements = [{k: e.get(k) for k in ("profil", "id", "kind", "label", "origin", "identifier_type",
                                             "identifier", "has_otp", "created_at")}
                    for e in res.get("elements", []) if isinstance(e, dict)]
        donnees = {"profils": profils, "elements": elements}
        self._cache = (maintenant, donnees)
        return donnees

    # -- validation -------------------------------------------------------------
    def _ligne(self, d: dict, cle: str, maximum: int, obligatoire: bool) -> str | None:
        v = d.get(cle)
        if v is None or (isinstance(v, str) and not v.strip()):
            if obligatoire:
                raise self._erreur(422, f"Champ « {cle} » obligatoire.")
            return None
        if not isinstance(v, str) or len(v) > maximum or re.search(r"[\x00-\x1f\x7f]", v):
            raise self._erreur(422, f"Champ « {cle} » invalide.")
        return v.strip()

    def _origine(self, d: dict, obligatoire: bool) -> str | None:
        v = self._ligne(d, "origin", 300, obligatoire)
        if v is not None and not RE_ORIGINE.match(v):
            raise self._erreur(422, "Origine invalide : schéma et hôte seulement, ex. https://exemple.fr.")
        return v

    def _secret(self, d: dict, type_: str, creation: bool) -> dict:
        brut = d.get("secret") or {}
        if not isinstance(brut, dict) or set(brut) - set(CHAMPS_SECRETS[type_]):
            raise self._erreur(422, "Champs secrets invalides.")
        secret = {}
        for cle, v in brut.items():
            if v is None or v == "":
                continue
            if not isinstance(v, str) or len(v) > SECRET_MAX or "\x00" in v:
                raise self._erreur(422, f"Champ « {cle} » invalide.")
            secret[cle] = v
        if creation:
            manquants = [c for c in OBLIGATOIRES[type_] if not secret.get(c, "").strip()]
            if manquants:
                raise self._erreur(422, "Champs obligatoires manquants : " + ", ".join(manquants) + ".")
        return secret

    def _poignee(self, d: dict, profil: str) -> dict:
        h = d.get("handle")
        if not isinstance(h, str) or not RE_POIGNEE.match(h):
            raise self._erreur(422, "Poignée invalide.")
        element = next((e for e in self.liste()["elements"] if e["profil"] == profil and e["id"] == h), None)
        if element is None:
            raise self._erreur(422, "Élément inconnu dans ce coffre.")
        return element

    def requete(self, action: str, d: dict) -> tuple[dict, str]:
        """Requête pour le script côté Hermes, après validation stricte. Renvoie (requête, HERMES_HOME)."""
        home = self._home_profil(d.get("profil"))
        dossier = os.path.join(home, "vault")
        profil = d["profil"]
        if action == "vault.delete":
            return {"op": "delete", "dossier": dossier, "handle": self._poignee(d, profil)["id"]}, home
        if action == "vault.create":
            type_ = d.get("kind")
            if type_ not in TYPES:
                raise self._erreur(422, "Type invalide (login, payment ou address).")
            req = {"op": "create", "dossier": dossier, "kind": type_, "label": self._ligne(d, "label", 100, True),
                   "origin": self._origine(d, True)}
            secret = self._secret(d, type_, True)
            if type_ == "login":
                if d.get("identifier_type") not in TYPES_IDENTIFIANT:
                    raise self._erreur(422, "Type d'identifiant invalide (email, phone ou username).")
                secret = {"identifier_type": d["identifier_type"],
                          "identifier": self._ligne(d, "identifier", 254, True), **secret}
            return req | {"secret": secret}, home
        if action == "vault.update":
            element = self._poignee(d, profil)
            type_ = element["kind"]
            req = {"op": "update", "dossier": dossier, "handle": element["id"],
                   "label": self._ligne(d, "label", 100, False), "origin": self._origine(d, False),
                   "secret": self._secret(d, type_, False), "retirer_otp": d.get("retirer_otp") is True}
            if type_ == "login":
                it = d.get("identifier_type")
                if it not in (None, "") and it not in TYPES_IDENTIFIANT:
                    raise self._erreur(422, "Type d'identifiant invalide (email, phone ou username).")
                req |= {"identifier_type": it or None, "identifier": self._ligne(d, "identifier", 254, False)}
            vider = d.get("vider") or []
            if not isinstance(vider, list) or not set(vider) <= set(CHAMPS_SECRETS[type_]) - set(OBLIGATOIRES[type_]):
                raise self._erreur(422, "Champs à vider invalides.")
            return req | {"vider": vider}, home
        raise self._erreur(404, "Action inconnue.")

    # -- actions ----------------------------------------------------------------
    def action(self, action: str, d: dict) -> tuple[int, dict]:
        req, home = self.requete(action, d)
        debut = time.monotonic()
        statut, res = self._executer(req, home)
        self._cache = None
        log.info("action %s : %s en %.1f s", action, "échec" if "erreur" in res else "ok", time.monotonic() - debut)
        if "erreur" in res:
            from hermes_os_pont import masquer
            return statut, {"ok": False, "action": action, "code": 1, "sortie": "",
                            "erreur": (masquer(str(res["erreur"])) or "")[:500]}
        poignee = (res.get("element") or {}).get("id") or res.get("supprime") or ""
        return 200, {"ok": True, "action": action, "code": 0, "sortie": str(poignee), "erreur": ""}
