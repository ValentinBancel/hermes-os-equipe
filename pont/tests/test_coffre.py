"""Coffre (vault) : validation stricte, secrets jamais renvoyés ni passés en argv, modification = drop + create."""
from __future__ import annotations

import os
import subprocess
import sys
import unittest
from unittest import mock

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

import faux_coffre  # noqa: E402
from test_pont import Base  # noqa: E402

MDP = "mot-de-passe-de-test-long"
MDP_NOUVEAU = "nouveau-mot-de-passe-de-test"
GRAINE = "JBSWYDPEHPKPXPAB"


class TestCoffre(Base):
    def setUp(self):
        super().setUp()
        self.pont.coffre = faux_coffre.coffre(self.pont, os.path.join(self.tmp, "agent"))
        self.dossier = os.path.join(self.home, "vault")

    def creer(self, **extra):
        donnees = {"profil": "default", "kind": "login", "label": "Malt", "origin": "https://www.malt.fr",
                   "identifier_type": "email", "identifier": "camille.martin@example.test",
                   "secret": {"password": MDP, "otp_secret": GRAINE}} | extra
        return self.post("vault.create", donnees)

    def test_liste_vide_puis_creation(self):
        statut, d = self.get("/v1/vault")
        self.assertEqual(statut, 200)
        self.assertEqual(d["elements"], [])
        self.assertIn("default", d["profils"])
        statut, r = self.creer()
        self.assertEqual(statut, 200, r)
        self.assertTrue(r["ok"], r)
        self.assertRegex(r["sortie"], r"^vault_[a-f0-9]{12}$")
        _, d = self.get("/v1/vault")
        e = d["elements"][0]
        self.assertEqual((e["profil"], e["label"], e["identifier"], e["has_otp"]), ("default", "Malt", "camille.martin@example.test", True))
        self.assertNotIn(MDP, repr(d))
        self.assertNotIn(GRAINE, repr(d))

    def test_secret_jamais_en_argv(self):
        vrai_run = subprocess.run
        argvs = []

        def espion(cmd, *a, **k):
            argvs.append(cmd)
            return vrai_run(cmd, *a, **k)

        with mock.patch("hermes_os_coffre.subprocess.run", side_effect=espion):
            statut, r = self.creer()
        self.assertTrue(r["ok"], r)
        self.assertTrue(argvs)
        for cmd in argvs:
            self.assertNotIn(MDP, " ".join(cmd))

    def test_modification_drop_puis_create(self):
        _, r = self.creer()
        ancien = r["sortie"]
        # Mot de passe vide : on garde l'ancien ; seul le libellé change.
        statut, r2 = self.post("vault.update", {"profil": "default", "handle": ancien, "label": "Malt pro", "secret": {}})
        self.assertTrue(r2["ok"], r2)
        self.assertNotEqual(r2["sortie"], ancien)
        items = faux_coffre.lire(self.dossier)
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["label"], "Malt pro")
        self.assertEqual(items[0]["secret"]["password"], MDP)
        self.assertTrue(items[0]["secret"]["otp_secret"].startswith(GRAINE))
        # Nouveau mot de passe et retrait de la clé 2FA.
        _, r3 = self.post("vault.update", {"profil": "default", "handle": r2["sortie"], "secret": {"password": MDP_NOUVEAU},
                                           "retirer_otp": True})
        self.assertTrue(r3["ok"], r3)
        items = faux_coffre.lire(self.dossier)
        self.assertEqual(items[0]["secret"], {"password": MDP_NOUVEAU})
        self.assertEqual(items[0]["identifier"], "camille.martin@example.test")

    def test_modification_invalide_ne_perd_rien(self):
        _, r = self.creer()
        statut, r2 = self.post("vault.update", {"profil": "default", "handle": r["sortie"], "secret": {"otp_secret": "pas une clé !"}})
        self.assertFalse(r2["ok"])
        items = faux_coffre.lire(self.dossier)
        self.assertEqual([i["id"] for i in items], [r["sortie"]])

    def test_suppression(self):
        _, r = self.creer()
        statut, r2 = self.post("vault.delete", {"profil": "default", "handle": r["sortie"]})
        self.assertTrue(r2["ok"], r2)
        self.assertEqual(faux_coffre.lire(self.dossier), [])
        # Corps différent : la même signature serait refusée comme rejeu.
        statut, r3 = self.post("vault.delete", {"handle": r["sortie"], "profil": "default"})
        self.assertEqual(statut, 422)

    def test_profil_par_coffre(self):
        profil = next(p for p in self.pont.profils_existants() if p != "default")
        _, r = self.creer(profil=profil)
        self.assertTrue(r["ok"], r)
        self.assertEqual(len(faux_coffre.lire(os.path.join(self.home, "profiles", profil, "vault"))), 1)
        self.assertEqual(faux_coffre.lire(self.dossier), [])
        _, d = self.get("/v1/vault")
        self.assertEqual(d["elements"][0]["profil"], profil)

    def test_adresse_et_carte(self):
        statut, r = self.post("vault.create", {"profil": "default", "kind": "address", "label": "Maison", "origin": "https://boutique.exemple.fr",
                                               "secret": {"address_line1": "1 rue X", "city": "Lyon", "postal_code": "69001", "country": "France"}})
        self.assertTrue(r["ok"], r)
        statut, r = self.post("vault.update", {"profil": "default", "handle": r["sortie"], "secret": {"city": "Villeurbanne"}})
        self.assertTrue(r["ok"], r)
        self.assertEqual(faux_coffre.lire(self.dossier)[0]["secret"]["city"], "Villeurbanne")
        self.assertEqual(faux_coffre.lire(self.dossier)[0]["secret"]["address_line1"], "1 rue X")

    def test_refus(self):
        cas = [
            {"profil": "inconnu"},
            {"profil": "../etc"},
            {"kind": "ssh"},
            {"label": ""},
            {"label": "a\nb"},
            {"origin": "malt.fr"},
            {"origin": "https://malt.fr/chemin?x=1"},
            {"origin": "javascript:alert(1)"},
            {"identifier_type": "nom"},
            {"identifier": ""},
            {"secret": {"password": ""}},
            {"secret": {"password": MDP, "autre": "x"}},
            {"secret": {"password": "x" * 2000}},
        ]
        for extra in cas:
            with self.subTest(extra=extra):
                statut, r = self.creer(**extra)
                self.assertEqual(statut, 422, r)
        self.assertEqual(faux_coffre.lire(self.dossier), [])
        for d in ({"profil": "default", "handle": "vault_zz"}, {"profil": "default", "handle": "vault_0123456789ab"}):
            self.assertEqual(self.post("vault.delete", d)[0], 422)

    def test_action_inconnue_et_coffre_desactive(self):
        self.assertEqual(self.post("vault.export", {"profil": "default"})[0], 404)
        self.pont.coffre = None
        self.assertEqual(self.get("/v1/vault")[0], 404)
        self.assertEqual(self.creer()[0], 404)

    def test_erreur_du_coffre_sans_secret(self):
        _, r = self.creer(secret={"password": MDP, "otp_secret": "pas-base32-" + MDP})
        self.assertFalse(r["ok"])
        self.assertNotIn(MDP, repr(r))


if __name__ == "__main__":
    unittest.main()
