"""Faux `agent.vault_store` de Hermes pour les tests du coffre (stdlib : la CI n'a pas `cryptography`).

Même interface publique que le vrai (add_item, list_items, remove_item, get_meta, resolve_secret, VaultError),
stockage JSON en clair dans `<dossier>/vault.json.enc` : seuls les tests le lisent.
"""
from __future__ import annotations

import os
import sys

SOURCE = r'''
import json, os, uuid
from dataclasses import dataclass


class VaultError(Exception):
    pass


@dataclass(frozen=True)
class VaultItemMeta:
    id: str
    kind: str
    label: str
    origin: object
    created_at: str
    identifier_type: object = None
    identifier: object = None
    has_otp: bool = False

    def to_dict(self):
        d = {"id": self.id, "kind": self.kind, "label": self.label, "origin": self.origin, "created_at": self.created_at}
        if self.identifier is not None:
            d["identifier"] = self.identifier
            d["identifier_type"] = self.identifier_type
        if self.has_otp:
            d["has_otp"] = True
        return d


class VaultStore:
    def __init__(self, base_dir):
        self._f = os.path.join(str(base_dir), "vault.json.enc")

    def _lire(self):
        if not os.path.exists(self._f):
            return []
        with open(self._f, encoding="utf-8") as h:
            return json.load(h)["items"]

    def _ecrire(self, items):
        os.makedirs(os.path.dirname(self._f), exist_ok=True)
        with open(self._f, "w", encoding="utf-8") as h:
            json.dump({"items": items}, h)

    @staticmethod
    def _meta(r):
        return VaultItemMeta(r["id"], r["kind"], r["label"], r["origin"], r["created_at"], r.get("identifier_type"),
                             r.get("identifier"), bool(r["secret"].get("otp_secret")))

    def add_item(self, kind, label, secret, origin=None):
        secret = dict(secret)
        rec = {"id": "vault_" + uuid.uuid4().hex[:12], "kind": kind, "label": label, "origin": origin,
               "created_at": "2026-10-16T10:00:00+00:00"}
        if kind == "login":
            if not secret.get("password"):
                raise VaultError("login items require identifier and password")
            otp = secret.get("otp_secret") or ""
            if otp.startswith("otpauth://"):
                otp = otp.split("secret=", 1)[1].split("&", 1)[0] + "|8|30|SHA1"
            if otp and otp.split("|")[0].strip("ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"):
                raise VaultError("authenticator key must be a base32 secret or an otpauth:// URI")
            rec |= {"identifier_type": secret.pop("identifier_type"), "identifier": secret.pop("identifier"),
                    "secret": {"password": secret["password"], **({"otp_secret": otp} if otp else {})}}
        else:
            rec["secret"] = secret
        items = self._lire()
        items.append(rec)
        self._ecrire(items)
        return self._meta(rec)

    def list_items(self):
        return [self._meta(r) for r in self._lire()]

    def remove_item(self, item_id):
        items = self._lire()
        reste = [r for r in items if r["id"] != item_id]
        self._ecrire(reste)
        return len(reste) != len(items)

    def get_meta(self, item_id):
        return next((self._meta(r) for r in self._lire() if r["id"] == item_id), None)

    def resolve_secret(self, item_id):
        for r in self._lire():
            if r["id"] == item_id:
                return dict(r["secret"])
        raise VaultError("no vault item")
'''


def construire(racine: str) -> str:
    """Crée une fausse racine de Hermes (`agent/vault_store.py`) ; renvoie son chemin."""
    os.makedirs(os.path.join(racine, "agent"), exist_ok=True)
    with open(os.path.join(racine, "agent", "__init__.py"), "w", encoding="utf-8"):
        pass
    with open(os.path.join(racine, "agent", "vault_store.py"), "w", encoding="utf-8") as h:
        h.write(SOURCE)
    return racine


def coffre(pont, racine: str):
    from hermes_os_coffre import Coffre
    return Coffre(pont, sys.executable, construire(racine), delai=20)


def lire(dossier: str) -> list[dict]:
    import json
    chemin = os.path.join(dossier, "vault.json.enc")
    if not os.path.exists(chemin):
        return []
    with open(chemin, encoding="utf-8") as h:
        return json.load(h)["items"]
