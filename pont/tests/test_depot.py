"""Aucun secret dans le dépôt : aucun fichier suivi par git ne contient un secret au format reconnaissable
(clés d'API, jetons, JWT, clés PEM, APP_KEY, ou clé sensible = valeur littérale aléatoire).
Les valeurs trouvées ne sont jamais affichées : seulement fichier:ligne et le type de motif."""
from __future__ import annotations

import os
import subprocess
import sys
import unittest

ICI = os.path.dirname(os.path.abspath(__file__))
RACINE = os.path.dirname(os.path.dirname(ICI))
sys.path.insert(0, os.path.dirname(ICI))

import hermes_os_pont as hp  # noqa: E402

EXCLUS = {
    # Secrets factices (appâts) : c'est leur rôle.
    "pont/tests/fixtures.py", "pont/tests/test_pont.py", "pont/tests/test_depot.py",
    "tests/Feature/Hermes/PontClientTest.php", "tests/Feature/Hermes/PontIntegrationTest.php",
    # APP_KEY de test publique, documentée dans le fichier (aucune donnée réelle chiffrée avec).
    "phpunit.xml",
}
EXTENSIONS_BINAIRES = {".ico", ".png", ".jpg", ".jpeg", ".gif", ".webp", ".woff", ".woff2", ".lock", ".sqlite"}


def fichiers_suivis() -> list[str]:
    r = subprocess.run(["git", "-C", RACINE, "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
                       capture_output=True, check=False)
    if r.returncode != 0:
        return []
    return sorted({f for f in r.stdout.decode().split("\0") if f})


class TestDepotSansSecret(unittest.TestCase):
    def test_aucun_secret_dans_le_depot(self):
        fichiers = fichiers_suivis()
        if not fichiers:
            self.skipTest("pas un dépôt git")
        trouves = []
        for f in fichiers:
            if f in EXCLUS or os.path.splitext(f)[1] in EXTENSIONS_BINAIRES or f.endswith("package-lock.json") \
                    or f.startswith(("vendor/", "node_modules/", "public/build/")):
                continue
            chemin = os.path.join(RACINE, f)
            if not os.path.isfile(chemin):
                continue
            with open(chemin, encoding="utf-8", errors="ignore") as h:
                for n, ligne in enumerate(h, 1):
                    motifs = hp.secrets_formats(ligne)
                    if motifs:
                        trouves.append(f"{f}:{n} ({motifs[0]})")
        self.assertEqual(trouves, [], "secrets dans le dépôt (valeurs non affichées)")

    def test_le_detecteur_trouve_un_vrai_secret(self):
        for ligne in ('APP_KEY=base64:' + "Ab1" * 15 + '=',
                      "HERMES_PONT_SECRET='a1b2c3d4e5f6a7b8c9d0'",
                      '"api_key": "sk-ant-api03-AbCdEfGh0123456789"',
                      "token: 'ghp_1234567890abcdefghijABCDEFGHIJ123456'"):
            with self.subTest(ligne=ligne[:12]):
                self.assertTrue(hp.secrets_formats(ligne))

    def test_le_detecteur_ignore_le_code_ordinaire(self):
        for ligne in ("$secret = app(Google2FA::class)->generateSecretKey();",
                      "'secret' => env('HERMES_PONT_SECRET'),", "Password::min(16)->uncompromised()",
                      'MDP="verif-$(head -c 24 /dev/urandom | base64)"', "self.secret = secret",
                      '-e APP_KEY="base64:$(head -c32 /dev/urandom | base64)"'):
            with self.subTest(ligne=ligne[:20]):
                self.assertEqual(hp.secrets_formats(ligne), [])


if __name__ == "__main__":
    unittest.main()
