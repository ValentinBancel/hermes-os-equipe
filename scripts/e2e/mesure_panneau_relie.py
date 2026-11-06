"""Mesure du panneau « Hermes relié » dans un vrai Chromium (lancé par scripts/mesure-panneau-relie.sh).

Pour chaque état du panneau, à 1440 et 390 px : `document.documentElement.scrollWidth <= innerWidth` (aucun défilement
horizontal). État « code affiché » : « Copier » dans la zone utile du panneau (`right <= panneau.right - 32`) et le code
sur une seule ligne. État « Code expiré » : navigateur en UTC, l'heure affichée doit être celle du serveur (fuseau de
l'application), pas celle du navigateur (m9).

Variables : URL, EMAIL, MOT_DE_PASSE, SECRET, OUTIL (commande « php scripts/e2e/mesure.php »), CAPTURES (dossier, facultatif).
Dépendance : Playwright pour Python (hors projet, déjà utilisé pour les mesures des lots précédents).
"""

import json
import os
import shlex
import subprocess
import sys
from datetime import datetime, timezone

from playwright.sync_api import sync_playwright

URL = os.environ["URL"]
EMAIL = os.environ["EMAIL"]
MOT_DE_PASSE = os.environ["MOT_DE_PASSE"]
SECRET = os.environ["SECRET"]
OUTIL = shlex.split(os.environ["OUTIL"])
CAPTURES = os.environ.get("CAPTURES", "")
LARGEURS = (1440, 390)
MARGE = 32

echecs: list[str] = []


def outil(*args: str) -> str:
    return subprocess.run([*OUTIL, *args], check=True, capture_output=True, text=True).stdout.strip()


def verifier(condition: bool, message: str) -> None:
    print(f"  {'OK' if condition else 'KO'}  {message}")
    if not condition:
        echecs.append(message)


def mesurer(page, etat: str) -> None:
    for largeur in LARGEURS:
        page.set_viewport_size({"width": largeur, "height": 900})
        page.wait_for_timeout(150)
        m = page.evaluate("""() => {
            const p = document.querySelector('aside.panneau-relie').getBoundingClientRect();
            return { sw: document.documentElement.scrollWidth, iw: innerWidth, pr: Math.round(p.right) };
        }""")
        verifier(m["sw"] <= m["iw"], f"{etat:<38} {largeur:>4} px : scrollWidth {m['sw']} <= innerWidth {m['iw']} (panneau → {m['pr']})")


def mesurer_code(page) -> None:
    for largeur in LARGEURS:
        page.set_viewport_size({"width": largeur, "height": 900})
        page.wait_for_timeout(150)
        m = page.evaluate("""() => {
            const r = (s) => document.querySelector(s).getBoundingClientRect();
            const p = r('aside.panneau-relie'), c = r('#btn-copier-code'), b = r('.panneau-relie .code-appairage b');
            const lh = parseFloat(getComputedStyle(document.querySelector('.panneau-relie .code-appairage b')).lineHeight);
            return { pr: p.right, cl: c.left, cr: c.right, ct: c.top, bt: b.top, bh: b.height, lh, sw: document.documentElement.scrollWidth, iw: innerWidth };
        }""")
        limite = m["pr"] - MARGE
        verifier(m["cr"] <= limite, f"{'code affiché : « Copier »':<38} {largeur:>4} px : x={m['cl']:.0f}–{m['cr']:.0f} <= panneau {m['pr']:.0f} − {MARGE} = {limite:.0f}"
                 + (" (passé sous le code)" if m["ct"] > m["bt"] + m["bh"] - 1 else " (même ligne que le code)"))
        verifier(m["bh"] < 2 * m["lh"], f"{'code affiché : code sur une ligne':<38} {largeur:>4} px : hauteur {m['bh']:.0f} < 2 × {m['lh']:.0f}")
        if CAPTURES:
            chemin = os.path.join(CAPTURES, f"L4-code-panneau-{largeur}.png")
            page.locator("aside.panneau-relie").scroll_into_view_if_needed()
            page.screenshot(path=chemin, full_page=True)
            print(f"        capture : {chemin}")


def attendre_confirmation(page) -> None:
    page.locator(".panneau-relie .confirmation-panneau").wait_for(state="visible")


def annuler(page) -> None:
    page.locator(".panneau-relie .confirmation-panneau [data-annuler]").click()
    page.locator(".panneau-relie .confirmation-panneau").wait_for(state="detached")


def recharger(page) -> None:
    page.goto(f"{URL}/compte")
    page.locator("aside.panneau-relie").wait_for()


def main() -> int:
    with sync_playwright() as pw:
        navigateur = pw.chromium.launch()
        # Navigateur en UTC, application en Europe/Paris : « Code expiré à HH:MM » doit suivre le serveur (m9).
        contexte = navigateur.new_context(timezone_id="UTC", locale="fr-FR", viewport={"width": 1440, "height": 900})
        contexte.clock.install()
        page = contexte.new_page()

        page.goto(f"{URL}/connexion")
        page.fill('input[name="email"]', EMAIL)
        page.fill('input[name="password"]', MOT_DE_PASSE)
        page.click('button[type="submit"]')
        page.wait_for_url("**/connexion/code")
        page.fill('input[name="code"]', outil("totp", SECRET))
        page.click('form button[type="submit"]')
        page.wait_for_load_state("networkidle")
        recharger(page)
        print(f"Connecté : {page.url}")

        # 1-4 : sans instance, confirmation, code affiché, code expiré.
        mesurer(page, "1. sans instance")
        page.click("#btn-generer")
        attendre_confirmation(page)
        mesurer(page, "2. confirmation du code")
        page.locator('.panneau-relie .confirmation-panneau button[type="submit"]').click()
        page.locator("#btn-copier-code").wait_for(state="visible")
        mesurer(page, "3. code affiché")
        mesurer_code(page)

        page.clock.fast_forward("11:00")
        page.locator(".panneau-relie .code-expire").wait_for(state="visible")
        texte = page.locator(".panneau-relie .code-expire").inner_text()
        mesurer(page, "4. code expiré")
        serveur = outil("expiration")
        expire_a = page.evaluate("() => Alpine.$data(document.querySelector('[x-data=codeAppairage]')).expireA")
        utc = datetime.fromtimestamp(expire_a, timezone.utc).strftime("%H:%M")
        verifier(f"Code expiré à {serveur}." in texte,
                 f"m9 : navigateur en UTC, affiché « {texte} » ; heure serveur {serveur} (en UTC ce serait {utc})")

        # 5-7 : instance en ligne, confirmation « Délier », confirmation « Remplacer … ».
        outil("instance", "en-ligne")
        recharger(page)
        verifier(page.locator(".panneau-relie .instance .ok").count() == 1, "instance « Hermes Équipe » en ligne affichée")
        mesurer(page, "5. instance en ligne")
        page.click("#btn-delier")
        attendre_confirmation(page)
        mesurer(page, "6. confirmation « Délier »")
        annuler(page)
        page.click("#btn-generer")
        attendre_confirmation(page)
        mesurer(page, "7. confirmation « Remplacer Hermes Équipe »")
        annuler(page)

        # 8 : hors ligne ; 9-11 : confirmations avec saisie du code TOTP (dernier code vieux d'1 h).
        outil("instance", "hors-ligne")
        recharger(page)
        verifier(page.locator(".panneau-relie .instance .hors-ligne").count() == 1, "instance hors ligne affichée (grisée)")
        mesurer(page, "8. instance hors ligne")
        print(f"        vieillir : {outil('vieillir')}")
        recharger(page)
        page.click("#btn-delier")
        page.locator(".panneau-relie #dl-code").wait_for(state="visible")
        mesurer(page, "9. « Délier » avec code TOTP")
        annuler(page)
        page.click("#btn-generer")
        page.locator(".panneau-relie #dl-code").wait_for(state="visible")
        mesurer(page, "10. « Remplacer » avec code TOTP")
        annuler(page)
        outil("delier")
        recharger(page)
        page.click("#btn-generer")
        page.locator(".panneau-relie #dl-code").wait_for(state="visible")
        mesurer(page, "11. sans instance, code TOTP")

        navigateur.close()

    print(json.dumps({"echecs": len(echecs)}))
    return 1 if echecs else 0


if __name__ == "__main__":
    sys.exit(main())
