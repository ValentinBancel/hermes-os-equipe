"""Mesure des cibles tactiles à 390 px (L6 tour 2, design-critic M1 et M2) — lancé par scripts/mesure-cibles.sh.

Règle mesurée : à 390 px, tout élément cliquable visible de la barre haute, du bandeau d'erreur et de `main` fait au
moins 40 px (var(--espace-10)) de haut. Une case à cocher compte pour son `label` (la cible réelle). Aucun débordement
horizontal (`scrollWidth <= innerWidth`) à 1440 et 390.

Coffre (M2) : la confirmation de suppression remplace la ligne visée (même position, à la marge près), reçoit le focus, bouton final
`.bouton-danger-plein` « Supprimer « <nom> » » ; le formulaire « Modifier » s'ouvre juste sous la ligne cliquée.

Variables : URL, EMAIL, EMAIL_NEUF, MOT_DE_PASSE, SECRET, OUTIL, HERMES_DEMO_ETAT, CAPTURES (dossier, facultatif).
Dépendance : Playwright pour Python (hors projet).
"""

import json
import os
import re
import shlex
import subprocess
import sys

from playwright.sync_api import sync_playwright

URL = os.environ["URL"]
EMAIL = os.environ["EMAIL"]
EMAIL_NEUF = os.environ["EMAIL_NEUF"]
MOT_DE_PASSE = os.environ["MOT_DE_PASSE"]
SECRET = os.environ["SECRET"]
OUTIL = shlex.split(os.environ["OUTIL"])
CAPTURES = os.environ.get("CAPTURES", "")
FIGE = os.environ.get("HERMES_DEMO_ETAT", "nominal") == "pont_injoignable"
# CAPTURES_PREFIXE : préfixe des captures (défaut « L6-dev2- », lot qui a introduit la mesure).
PREFIXE = os.environ.get("CAPTURES_PREFIXE", "L6-dev2-") + ("fige-" if FIGE else "")
CIBLE = 40

echecs: list[str] = []

CIBLES_JS = """(minimum) => {
    const zones = [...document.querySelectorAll('header.barre, main, body > .conteneur > .etat-erreur, .conteneur > .etat-erreur')];
    const vus = new Set(), petits = [];
    let total = 0;
    const visible = (el) => {
        // checkVisibility : écarte aussi le contenu d'un <details> fermé (content-visibility: hidden).
        return el.getClientRects().length > 0 && el.checkVisibility({ visibilityProperty: true, opacityProperty: true });
    };
    for (const zone of zones) {
        for (let el of zone.querySelectorAll('a[href], button, summary, select, input:not([type=hidden]), textarea, [role=button]')) {
            if (el.matches('input[type=checkbox], input[type=radio]') && el.closest('label')) el = el.closest('label');
            if (vus.has(el) || !visible(el)) continue;
            vus.add(el);
            total++;
            const r = el.getBoundingClientRect();
            if (r.height < minimum - 0.5) {
                const p = el.parentElement;
                petits.push(`${p.tagName.toLowerCase()}${p.className ? '.' + String(p.className).trim().split(/\\s+/).join('.') : ''} > ${el.tagName.toLowerCase()}${el.className ? '.' + String(el.className).trim().split(/\\s+/).join('.') : ''} « ${(el.innerText || el.value || el.getAttribute('aria-label') || '').trim().replace(/\\s+/g, ' ').slice(0, 40)} » ${Math.round(r.width)}×${Math.round(r.height)}`);
            }
        }
    }
    return { total, petits, sw: document.documentElement.scrollWidth, iw: innerWidth };
}"""


def outil(*args: str) -> str:
    return subprocess.run([*OUTIL, *args], check=True, capture_output=True, text=True).stdout.strip()


def verifier(condition: bool, message: str) -> None:
    print(f"  {'OK' if condition else 'KO'}  {message}")
    if not condition:
        echecs.append(message)


def capturer(page, nom: str, largeur: int) -> None:
    if CAPTURES:
        chemin = os.path.join(CAPTURES, f"{PREFIXE}{nom}-{largeur}.png")
        page.screenshot(path=chemin, full_page=True)
        print(f"        capture : {chemin}")


def mesurer(page, nom: str, capture: bool = True, avant=None) -> None:
    """1440 : défilement horizontal (+ capture) ; 390 : défilement + cibles ≥ 40 px (+ capture)."""
    for largeur in (1440, 390):
        page.set_viewport_size({"width": largeur, "height": 900})
        page.wait_for_timeout(200)
        if avant:
            avant(page)
        m = page.evaluate(CIBLES_JS, CIBLE)
        verifier(m["sw"] <= m["iw"], f"{nom:<34} {largeur:>4} px : scrollWidth {m['sw']} <= {m['iw']}")
        if largeur == 390:
            verifier(not m["petits"], f"{nom:<34}  390 px : {m['total']} cibles, {len(m['petits'])} < {CIBLE} px"
                     + "".join(f"\n        - {p}" for p in m["petits"]))
        if capture:
            capturer(page, nom, largeur)


def aller(page, chemin: str) -> None:
    page.goto(f"{URL}{chemin}")
    page.wait_for_load_state("networkidle")


def premier_lien(page, motif: str) -> str:
    hrefs = page.eval_on_selector_all("main a[href]", "els => els.map(e => e.getAttribute('href'))")
    for h in hrefs:
        chemin = re.sub(r"^https?://[^/]+", "", h)
        if re.fullmatch(motif, chemin):
            return chemin
    raise RuntimeError(f"aucun lien {motif} sur {page.url}")


def connexion(page, email: str, totp: bool) -> None:
    page.goto(f"{URL}/connexion")
    page.fill('input[name="email"]', email)
    page.fill('input[name="password"]', MOT_DE_PASSE)
    page.click('button[type="submit"]')
    if totp:
        page.wait_for_url("**/connexion/code")
        page.fill('input[name="code"]', outil("totp", SECRET))
        page.click('form button[type="submit"]')
    page.wait_for_load_state("networkidle")


def coffre(page) -> None:
    """M2 : confirmation à la place de la ligne, focus, bouton rouge plein ; « Modifier » sous la ligne."""
    aller(page, "/vault")
    for largeur in (1440, 390):
        page.set_viewport_size({"width": largeur, "height": 900})
        aller(page, "/vault")
        ligne = page.locator("main .ligne").nth(1)
        nom = ligne.locator(".ligne-texte b").inner_text().strip()
        avant = ligne.bounding_box()
        ligne.locator("button", has_text="Supprimer").click()
        conf = page.locator("main .confirmation-ligne")
        conf.wait_for(state="visible")
        page.wait_for_timeout(150)
        apres = conf.bounding_box()
        m = page.evaluate("""() => {
            const c = document.querySelector('main .confirmation-ligne');
            const b = c.querySelector('button[type=submit]');
            const s = getComputedStyle(c);
            return { focus: c.contains(document.activeElement), classe: b.className, texte: b.innerText.trim(),
                     fond: s.backgroundColor, filet: s.borderTopWidth + ' ' + s.borderTopColor, rayon: s.borderTopLeftRadius,
                     verre: s.backdropFilter, annuler: c.querySelector('[data-annuler]').className };
        }""")
        # À la place de la ligne : même départ, à la marge près (var(--espace-3) = 12 px).
        verifier(0 <= apres["y"] - avant["y"] <= 13, f"M2 {largeur:>4} px : confirmation à y={apres['y']:.0f}, ligne visée à y={avant['y']:.0f}")
        verifier(m["focus"], f"M2 {largeur:>4} px : focus dans la confirmation")
        verifier("bouton-danger-plein" in m["classe"] and m["texte"] == f"Supprimer « {nom} »",
                 f"M2 {largeur:>4} px : bouton final « {m['texte']} » ({m['classe']})")
        verifier(m["annuler"] == "bouton-texte", f"M2 {largeur:>4} px : « Annuler » en {m['annuler']}")
        verifier(m["verre"] in ("none", ""), f"M2 {largeur:>4} px : fond {m['fond']}, filet {m['filet']}, rayon {m['rayon']}, verre {m['verre']}")
        if largeur == 390:
            mt = page.evaluate(CIBLES_JS, CIBLE)
            verifier(not mt["petits"], f"{'coffre : confirmation':<34}  390 px : {mt['total']} cibles, {len(mt['petits'])} < {CIBLE} px"
                     + "".join(f"\n        - {p}" for p in mt["petits"]))
        capturer(page, "vault-suppression", largeur)
        page.locator("main .confirmation-ligne [data-annuler]").click()
        conf.wait_for(state="detached")

        ligne = page.locator("main .ligne").nth(1)
        bas = ligne.bounding_box()
        ligne.locator("button", has_text="Modifier").click()
        form = page.locator("main .formulaire-ligne")
        form.wait_for(state="visible")
        page.wait_for_timeout(150)
        f = form.bounding_box()
        verifier(0 <= f["y"] - (bas["y"] + bas["height"]) <= 2,
                 f"M2 {largeur:>4} px : formulaire « Modifier » à y={f['y']:.0f}, sous la ligne (bas {bas['y'] + bas['height']:.0f})")
        if largeur == 390:
            mt = page.evaluate(CIBLES_JS, CIBLE)
            verifier(not mt["petits"], f"{'coffre : modification':<34}  390 px : {mt['total']} cibles, {len(mt['petits'])} < {CIBLE} px"
                     + "".join(f"\n        - {p}" for p in mt["petits"]))
        capturer(page, "vault-modification", largeur)


def main() -> int:
    with sync_playwright() as pw:
        navigateur = pw.chromium.launch()
        contexte = navigateur.new_context(locale="fr-FR", viewport={"width": 1440, "height": 900})
        page = contexte.new_page()

        # Écrans imposés : connexion (sans session) puis enrôlement (compte sans TOTP).
        aller(page, "/connexion")
        mesurer(page, "connexion")
        connexion(page, EMAIL_NEUF, totp=False)
        page.wait_for_url("**/securite/totp")
        mesurer(page, "enrolement")
        contexte.clear_cookies()

        connexion(page, EMAIL, totp=True)
        print(f"Connecté : {page.url} (état {'figé' if FIGE else 'nominal'})")

        aller(page, "/planifiees")
        fiche = premier_lien(page, r"/planifiees/[a-f0-9]{12}")
        aller(page, "/messagerie")
        interlocuteur = premier_lien(page, r"/messagerie/[a-z0-9_-]+")
        aller(page, interlocuteur)
        try:
            conversation = premier_lien(page, r"/messagerie/[a-z0-9_-]+/c_[a-f0-9]{16}")
        except RuntimeError:
            conversation = None

        pages = [("accueil", "/"), ("planifiees", "/planifiees"), ("fiche", fiche), ("compte", "/compte"),
                 ("consommation", "/consommation"), ("sessions", "/consommation/sessions"),
                 ("sessions-en-cours", "/consommation/sessions?etat=en-cours"), ("poles", "/poles"),
                 ("vault", "/vault"), ("messagerie", "/messagerie"), ("interlocuteur", interlocuteur),
                 ("journal", "/compte/journal")]
        if conversation:
            pages.append(("conversation", conversation))
        aller(page, "/poles")
        try:
            pages.append(("profil", premier_lien(page, r"/poles/[a-z0-9_-]+")))
        except RuntimeError:
            pass

        for nom, chemin in pages:
            aller(page, chemin)
            mesurer(page, nom, capture=nom in ("accueil", "sessions-en-cours", "compte", "consommation", "poles", "vault", "planifiees", "fiche"))

        # Menu mobile ouvert (barre à 390).
        aller(page, "/")
        page.set_viewport_size({"width": 390, "height": 900})
        page.click(".menu-mobile summary")
        m = page.evaluate(CIBLES_JS, CIBLE)
        verifier(not m["petits"], f"{'menu mobile ouvert':<34}  390 px : {m['total']} cibles, {len(m['petits'])} < {CIBLE} px"
                 + "".join(f"\n        - {p}" for p in m["petits"]))

        if FIGE:
            # dc m5 L6 : état figé rendu à l'écran — message « indisponibles tant que Hermes ne répond pas », aucune action active.
            for nom, chemin in (("planifiees", "/planifiees"), ("fiche", fiche), ("vault", "/vault")):
                aller(page, chemin)
                m = page.evaluate("""() => ({
                    texte: document.querySelector('main').innerText,
                    actifs: [...document.querySelectorAll('main button:not([disabled])')].filter(b => b.checkVisibility())
                        .map(b => b.innerText.trim()).filter(t => /Lancer|Suspendre|Reprendre|Mettre en pause|Supprimer|Modifier|Ajouter/.test(t)),
                })""")
                if nom != "planifiees":
                    verifier("tant que Hermes ne répond pas" in m["texte"], f"m5 {nom:<10} : message « … tant que Hermes ne répond pas » affiché")
                verifier(not m["actifs"], f"m5 {nom:<10} : aucune action active ({', '.join(m['actifs']) or 'aucune'})")
        else:
            coffre(page)

        navigateur.close()

    print(json.dumps({"echecs": len(echecs)}))
    return 1 if echecs else 0


if __name__ == "__main__":
    sys.exit(main())
