#!/usr/bin/env python3
"""Côté Hermes du coffre (« vault ») de la console hermes-os.

Lancé par le pont (hermes_os_coffre.py) avec l'interpréteur Python de Hermes, car le coffre est chiffré
(Fernet, paquet `cryptography`) et seul `agent.vault_store` de Hermes sait le lire et l'écrire, verrou compris.
La CLI `hermes vault add` est interactive (getpass) : elle ne peut pas être pilotée.

Protocole : une requête JSON sur l'entrée standard, une réponse JSON sur la sortie standard.
- `{"op": "list", "dossiers": {"default": "~/.hermes/vault", ...}}` → `{"elements": [...]}` (métadonnées)
- `{"op": "create", "dossier", "kind", "label", "origin", "secret"}` → `{"element": {...}}`
- `{"op": "update", "dossier", "handle", "label", "origin", "secret", "retirer_otp"}` → `{"element", "ancien"}`
  (supprime l'ancien élément et crée le nouveau : Hermes ne sait pas modifier un élément ; un champ secret
  laissé vide reprend l'ancienne valeur, sans qu'elle sorte jamais de ce processus)
- `{"op": "delete", "dossier", "handle"}` → `{"supprime": handle}`
En cas d'échec : `{"erreur": "..."}` et code de sortie 1. Aucune valeur secrète n'est jamais écrite en sortie.
"""
from __future__ import annotations

import json
import os
import sys
from pathlib import Path


def _otp_reutilisable(stocke: str) -> str:
    """Forme stockée `GRAINE|chiffres|période|algo` → URI otpauth:// que `add_item` sait relire."""
    if "|" not in stocke:
        return stocke
    graine, chiffres, periode, algo = stocke.split("|", 3)
    return f"otpauth://totp/hermes?secret={graine}&digits={chiffres}&period={periode}&algorithm={algo}"


def _meta(m) -> dict:
    d = m.to_dict()
    return {
        "id": d["id"], "kind": d["kind"], "label": d["label"], "origin": d.get("origin"),
        "identifier_type": d.get("identifier_type"), "identifier": d.get("identifier"),
        "has_otp": bool(d.get("has_otp")), "created_at": d.get("created_at"),
    }


def traiter(req: dict) -> dict:
    from agent.vault_store import VaultStore

    op = req.get("op")
    if op == "list":
        elements = []
        for profil, dossier in (req.get("dossiers") or {}).items():
            if not os.path.isfile(os.path.join(dossier, "vault.json.enc")):
                continue
            for m in VaultStore(Path(dossier)).list_items():
                elements.append({"profil": profil, **_meta(m)})
        return {"elements": elements}

    store = VaultStore(Path(req["dossier"]))

    if op == "create":
        meta = store.add_item(kind=req["kind"], label=req["label"], secret=req.get("secret") or {},
                              origin=req.get("origin"))
        return {"element": _meta(meta)}

    if op == "delete":
        if not store.remove_item(req["handle"]):
            return {"erreur": "Élément introuvable."}
        return {"supprime": req["handle"]}

    if op == "update":
        handle = req["handle"]
        ancien = store.get_meta(handle)
        if ancien is None:
            return {"erreur": "Élément introuvable."}
        ancien_secret = store.resolve_secret(handle)
        saisi = {k: v for k, v in (req.get("secret") or {}).items() if isinstance(v, str) and v.strip()}
        if ancien.kind == "login":
            secret = {
                "identifier_type": req.get("identifier_type") or ancien.identifier_type,
                "identifier": req.get("identifier") or ancien.identifier,
                "password": saisi.get("password") or ancien_secret.get("password", ""),
            }
            if saisi.get("otp_secret"):
                secret["otp_secret"] = saisi["otp_secret"]
            elif ancien_secret.get("otp_secret") and not req.get("retirer_otp"):
                secret["otp_secret"] = _otp_reutilisable(ancien_secret["otp_secret"])
        else:
            vider = set(req.get("vider") or [])
            secret = {k: v for k, v in ancien_secret.items() if k not in vider}
            secret.update(saisi)
        # Drop puis création, choix de conception ; on crée d'abord pour ne jamais perdre l'élément si la
        # création échoue (validation), puis on supprime l'ancien.
        nouveau = store.add_item(kind=ancien.kind, label=req.get("label") or ancien.label, secret=secret,
                                 origin=req.get("origin") or ancien.origin)
        if not store.remove_item(handle):
            store.remove_item(nouveau.id)
            return {"erreur": "L'ancien élément a disparu pendant la modification ; rien n'a été changé."}
        return {"element": _meta(nouveau), "ancien": handle}

    return {"erreur": "Opération inconnue."}


def main() -> int:
    racine = os.environ.get("HERMES_OS_AGENT_ROOT", "")
    if racine:
        sys.path.insert(0, racine)
    try:  # chemins des dépendances de Hermes (cryptography, ruamel…), comme le lanceur `hermes`
        import hermes_bootstrap  # noqa: F401
    except ImportError:
        pass
    try:
        req = json.loads(sys.stdin.read() or "{}")
        if not isinstance(req, dict):
            raise ValueError
    except ValueError:
        print(json.dumps({"erreur": "Requête illisible."}))
        return 1
    try:
        from agent.vault_store import VaultError
    except ImportError as e:
        print(json.dumps({"erreur": f"Coffre de Hermes introuvable ({type(e).__name__})."}))
        return 1
    try:
        res = traiter(req)
    except VaultError as e:  # messages sûrs par construction (vault_store : « never contains secret values »)
        res = {"erreur": str(e)}
    except Exception as e:  # noqa: BLE001 - jamais le message : il pourrait contenir une valeur
        res = {"erreur": f"Échec du coffre ({type(e).__name__})."}
    print(json.dumps(res, ensure_ascii=False))
    return 1 if "erreur" in res else 0


if __name__ == "__main__":
    sys.exit(main())
