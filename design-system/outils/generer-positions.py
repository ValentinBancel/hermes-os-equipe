#!/usr/bin/env python3
"""Génère les classes de position de composants.css (section 14) : frise-t-0..1440 (minutes) et histo-h-0..100.
Usage : python3 design-system/outils/generer-positions.py (réécrit le bloc entre POSITIONS-DEBUT et POSITIONS-FIN)."""
import pathlib, re

chemin = pathlib.Path(__file__).resolve().parent.parent / "composants.css"
lignes = [f".frise-t-{n} {{ left: {n / 1440 * 100:.3f}%; }}" for n in range(1441)]
lignes += [f".histo-h-{n} {{ height: {n}%; }}" for n in range(101)]
bloc = "/* POSITIONS-DEBUT */\n" + "\n".join(lignes) + "\n/* POSITIONS-FIN */"
css = chemin.read_text(encoding="utf-8")
css = re.sub(r"/\* POSITIONS-DEBUT \*/.*?/\* POSITIONS-FIN \*/", lambda _: bloc, css, flags=re.S)
chemin.write_text(css, encoding="utf-8")
print(f"{len(lignes)} classes écrites dans {chemin.name}")
