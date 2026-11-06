#!/usr/bin/env python3
"""Parcours HTTP authentifié de la console, sans navigateur (L5) : ce que ferait l'utilisateur à l'écran.

Outil des vérifications de bout en bout (scripts/verifier-clone-neuf.sh, CI). Stdlib seule. La session (cookies)
est gardée dans un fichier entre deux appels ; aucun secret n'est affiché (mot de passe lu sur l'entrée standard,
clé TOTP jamais écrite).

    parcours_console.py code     <base> <email> <session>  < mot_de_passe
        connexion → enrôlement TOTP (clé lue à l'écran, code calculé) → Compte → « Relier un Hermes » →
        confirmation → affiche le code d'appairage reçu par le navigateur (XXXX-XXXX)
    parcours_console.py verifier <base> <session> <nom_instance> <texte_accueil>...
        Compte affiche l'instance reliée « En ligne » ; l'accueil (chargé comme le navigateur, en différé) affiche
        chaque texte attendu (valeurs du home de démonstration), et aucun texte préfixé par « ! »
"""

from __future__ import annotations

import base64
import hashlib
import hmac
import html
import http.cookiejar
import json
import re
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser


class Echec(Exception):
    pass


class _SansRedirection(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Navigateur:
    def __init__(self, base: str, session: str, charger: bool) -> None:
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.MozillaCookieJar(session)
        if charger:
            try:
                self.jar.load(ignore_discard=True, ignore_expires=True)
            except OSError as e:
                raise Echec(f"session introuvable ({e.strerror}) : lancer d'abord « code »") from None
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _SansRedirection)

    def requete(self, chemin: str, donnees: dict | None = None, json_corps: dict | None = None,
                entetes: dict | None = None) -> tuple[int, str, str]:
        corps, h = None, {"Accept": "text/html,application/json", **(entetes or {})}
        if donnees is not None:
            corps = urllib.parse.urlencode(donnees).encode()
            h["Content-Type"] = "application/x-www-form-urlencoded"
        if json_corps is not None:
            corps = json.dumps(json_corps).encode()
            h["Content-Type"] = "application/json"
        req = urllib.request.Request(urllib.parse.urljoin(self.base + "/", chemin), data=corps, headers=h)
        try:
            with self.opener.open(req, timeout=30) as r:
                return r.status, r.read().decode(), ""
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(errors="replace"), e.headers.get("Location", "")

    def get(self, chemin: str, attendu: int = 200) -> str:
        code, corps, loc = self.requete(chemin)
        if code != attendu:
            raise Echec(f"GET {chemin} -> {code} {loc} ({attendu} attendu)")
        return corps

    def post(self, chemin: str, donnees: dict, vers: str) -> None:
        code, _, loc = self.requete(chemin, donnees=donnees)
        if code != 302 or (urllib.parse.urlparse(loc).path or "/") != vers:
            raise Echec(f"POST {chemin} -> {code} {loc} (302 vers {vers} attendu)")

    def sauver(self) -> None:
        self.jar.save(ignore_discard=True, ignore_expires=True)


def jeton(page: str) -> str:
    m = re.search(r'name="_token" value="([^"]+)"', page) or re.search(r'data-csrf="([^"]+)"', page)
    if not m:
        raise Echec("jeton CSRF introuvable")
    return m.group(1)


def totp(cle: str, instant: float | None = None) -> str:
    """RFC 6238 (SHA-1, 30 s, 6 chiffres), comme l'application d'authentification."""
    secret = base64.b32decode(cle.replace(" ", "").upper() + "=" * (-len(cle.replace(" ", "")) % 8))
    compteur = int((time.time() if instant is None else instant) // 30)
    h = hmac.new(secret, struct.pack(">Q", compteur), hashlib.sha1).digest()
    o = h[-1] & 0x0F
    return f"{(struct.unpack('>I', h[o:o + 4])[0] & 0x7FFFFFFF) % 1_000_000:06d}"


class _Composants(HTMLParser):
    """Racines Livewire d'une page : snapshot + appel de chargement différé éventuel (#[Lazy])."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.composants: list[dict] = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if "wire:snapshot" in a:
            differe = None
            for k in ("x-intersect", "x-init"):
                m = re.search(r"__lazyLoad\('([^']+)'\)", a.get(k) or "")
                if m:
                    differe = m.group(1)
            nom = json.loads(a["wire:snapshot"] or "{}")["memo"]["name"]
            self.composants.append({"nom": nom, "snapshot": a["wire:snapshot"], "differe": differe})


def composant(page: str, nom: str) -> dict:
    p = _Composants()
    p.feed(page)
    for c in p.composants:
        if c["nom"] == nom:
            return c
    raise Echec(f"composant Livewire « {nom} » absent ({[c['nom'] for c in p.composants]})")


def livewire(nav: Navigateur, page: str, c: dict, appels: list[tuple[str, list]]) -> dict:
    m = re.search(r'data-update-uri="([^"]+)"', page)
    if not m:
        raise Echec("data-update-uri introuvable")
    corps = {"_token": jeton(page), "components": [{
        "snapshot": c["snapshot"], "updates": {},
        "calls": [{"path": "", "method": meth, "params": params} for meth, params in appels]}]}
    code, rep, _ = nav.requete(urllib.parse.urlparse(html.unescape(m.group(1))).path, json_corps=corps, entetes={"X-Livewire": "1"})
    if code != 200:
        raise Echec(f"requête Livewire {[a[0] for a in appels]} -> {code}")
    return json.loads(rep)["components"][0]


def cmd_code(base: str, email: str, session: str) -> None:
    mdp = sys.stdin.readline().rstrip("\n")
    nav = Navigateur(base, session, charger=False)
    nav.post("/connexion", {"_token": jeton(nav.get("/connexion")), "email": email, "password": mdp}, "/")
    enrolement = nav.get("/securite/totp")
    m = re.search(r"<span>Clé</span><span[^>]*>([A-Z2-7 ]+)</span>", enrolement)
    if not m:
        raise Echec("clé TOTP absente de l'écran d'enrôlement")
    nav.post("/securite/totp", {"_token": jeton(enrolement), "code": totp(m.group(1))}, "/securite/codes")
    nav.get("/securite/codes")

    page = nav.get("/compte")
    rep = livewire(nav, page, composant(page, "compte"), [("demanderCodeAppairage", []), ("confirmer", [])])
    codes = [d["params"]["code"] for d in rep.get("effects", {}).get("dispatches", []) if d.get("name") == "code-appairage"]
    if not codes:
        raise Echec("aucun code d'appairage reçu après confirmation dans Compte")
    nav.sauver()
    print(codes[0])


def cmd_verifier(base: str, session: str, nom: str, textes: list[str]) -> None:
    nav = Navigateur(base, session, charger=True)
    compte = html.unescape(nav.get("/compte"))
    if not re.search(rf'<div class="instance">\s*<b>{re.escape(nom)}</b>', compte):
        raise Echec(f"Compte n'affiche pas l'instance « {nom} »")
    if '<span class="ok">En ligne</span>' not in compte:
        raise Echec(f"Compte : instance « {nom} » pas « En ligne »")

    page = nav.get("/")
    c = composant(page, "accueil")
    if c["differe"] is None:
        rendu = page
    else:
        rendu = livewire(nav, page, c, [("__lazyLoad", [c["differe"]])]).get("effects", {}).get("html", "")
    rendu = re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", rendu)))
    manquants = [t for t in textes if not t.startswith("!") and t not in rendu]
    interdits = [t[1:] for t in textes if t.startswith("!") and t[1:] in rendu]
    if manquants or interdits:
        raise Echec(f"accueil sans {manquants} / avec {interdits}")
    vus = [f"« {t} »" if not t.startswith("!") else f"sans « {t[1:]} »" for t in textes]
    print(f"Compte : « {nom} » En ligne ; accueil : {', '.join(vus)}")


def main(argv: list[str]) -> int:
    try:
        if len(argv) == 4 and argv[0] == "code":
            cmd_code(*argv[1:])
        elif len(argv) >= 5 and argv[0] == "verifier":
            cmd_verifier(argv[1], argv[2], argv[3], argv[4:])
        else:
            print(__doc__, file=sys.stderr)
            return 2
    except Echec as e:
        print(f"ÉCHEC : {e}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
