"""Tests du pont hermes-os (stdlib unittest). Lancer depuis la racine du dépôt :
    python3 -m unittest discover -s pont/tests -v
"""
from __future__ import annotations

import http.client
import json
import os
import re
import shutil
import socket
import stat
import sys
import tempfile
import threading
import time
import unittest

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(ICI))
sys.path.insert(0, ICI)

import fixtures  # noqa: E402
import hermes_os_pont as hp  # noqa: E402


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp(prefix="pont-test-")
        self.home = os.path.join(self.tmp, "hermes")
        self.dehors = os.path.join(self.tmp, "dehors")
        os.makedirs(self.home)
        os.makedirs(self.dehors)
        fixtures.construire(self.home, self.dehors)
        self.journal = os.path.join(self.tmp, "appels.jsonl")
        self.bin = fixtures.faux_hermes(os.path.join(self.tmp, "bin", "hermes"), self.journal)
        self.pont = hp.Pont(self.home, fixtures.SECRET_PONT, self.bin, delai_action=5,
                           poles_chemin=os.path.join(self.home, "studio", "organisation", "poles.yaml"))

    def tearDown(self):
        shutil.rmtree(self.tmp, ignore_errors=True)

    def get(self, chemin: str):
        return self.pont.traiter("GET", chemin, fixtures.signer("GET", chemin), b"")

    def post(self, action: str, donnees) -> tuple[int, dict]:
        chemin = f"/v1/actions/{action}"
        corps = json.dumps(donnees).encode()
        return self.pont.traiter("POST", chemin, fixtures.signer("POST", chemin, corps), corps)

    def appels(self) -> list[dict]:
        if not os.path.exists(self.journal):
            return []
        with open(self.journal, encoding="utf-8") as f:
            return [json.loads(x) for x in f if x.strip()]


# Toutes les routes de lecture, avec des paramètres réels de la fixture.
ROUTES = [
    "/v1/sante", "/v1/gateway", "/v1/a-decider",
    "/v1/cron", "/v1/cron/31cd7657668e", "/v1/cron/7b72a4dcb9a6", "/v1/cron/31cd7657668e/sorties/2026-10-08_08-01-28.md",
    "/v1/consommation", "/v1/consommation?jours=7", "/v1/consommation/sessions", "/v1/poles", "/v1/erreurs",
    "/v1/erreurs?heures=72",
]


class TestMasqueur(unittest.TestCase):
    ECHANTILLONS = {
        "sk-ant-api03-AbCdEfGhIjKlMnOpQrStUvWxYz0123456789": "sk-ant",
        "clé OpenAI sk-proj-abcdefghijklmnop1234 ici": "sk-proj",
        "ghp_1234567890abcdefghijABCDEFGHIJ123456": "ghp_",
        "github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz": "github_pat_",
        "xo" "xb-123456789012-abcdefghijklmnop": "xo" "xb-",
        "xo" "xp-1-2-3-abcdef0123": "xo" "xp-",
        "AKIAIOSFODNN7EXAMPLE": "AKIA",
        "eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U": "eyJ",
        "Authorization: Bearer abcdef0123456789abcdef": "abcdef0123456789",
        "password=hunter2-tres-secret": "hunter2",
        "PASSWORD: \"hunter2-tres-secret\"": "hunter2",
        "token=abc123def456": "abc123def456",
        '{"api_key": "zz-9988776655"}': "9988776655",
        "secret = maValeurSecrete": "maValeurSecrete",
        "client_secret=GOCSPX-abcdef": "GOCSPX",
        "HERMES_PONT_SECRET=0123abcdef0123abcdef": "0123abcdef",
        "DB_PASSWORD: S3cr3tValue": "S3cr3tValue",
        "mot de passe : password: P@ss-w0rd!": "P@ss",
        "mdp=Azerty123456": "Azerty",
        "postgres://admin:Sup3rS3cret@db.local:5432/x": "Sup3rS3cret",
        "-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIVATE KEY-----": "MIIEpAIBAAKCAQEA",
        "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA (tronquée)": "b3BlbnNzaC1rZXktdjEAAAAA",
        "AIzaSyA-1234567890abcdefghijklmnopqrstu": "AIzaSy",
        "MTA4" "NzY1NDMyMTAxMjM0NTY3OA.GhIjKl.abcdefghijklmnopqrstuvwxyz0123": "GhIjKl.abcdef",
        "https://discord.com/api/webhooks/123456/abcDEF-ghi_jkl": "abcDEF-ghi",
        "https://hooks.slack.com/services/T000/B000/XXXXXXXX": "XXXXXXXX",
    }

    def test_aucun_motif_ne_sort(self):
        for brut, fragment in self.ECHANTILLONS.items():
            with self.subTest(brut=brut[:40]):
                masque = hp.masquer(brut)
                self.assertNotIn(fragment, masque)
                self.assertIn(hp.MASQUE, masque)

    def test_motifs_dans_un_texte_long(self):
        texte = "\n".join(f"ligne {i} : {s} fin" for i, s in enumerate(self.ECHANTILLONS))
        masque = hp.masquer(texte)
        for fragment in self.ECHANTILLONS.values():
            self.assertNotIn(fragment, masque)

    def test_idempotent_et_texte_normal_intact(self):
        normal = ("Lot 05 : pont hôte. Tokens par jour : 226 M. Voir t_20e38ac9 et le job 31cd7657668e. "
                  "Le mot « secret » seul n'est pas masqué ; 2026-10-08T08:00:00+02:00.")
        self.assertEqual(hp.masquer(normal), normal)
        une = hp.masquer("password=abcdef sk-ant-api03-AbCdEfGhIjKlMnOpQr")
        self.assertEqual(hp.masquer(une), une)

    def test_structures_json(self):
        donnees = {"a": ["Bearer abcdefgh12345678"], "b": {"c": "token=xyz12345"}, "n": 3, "v": None}
        masque = hp.masquer_json(donnees)
        self.assertNotIn("abcdefgh12345678", json.dumps(masque))
        self.assertNotIn("xyz12345", json.dumps(masque))
        self.assertEqual(masque["n"], 3)
        self.assertIsNone(hp.masquer(None))


class TestSignature(Base):
    def test_sans_signature(self):
        statut, corps = self.pont.traiter("GET", "/v1/sante", {}, b"")
        self.assertEqual(statut, 401)

    def test_signature_fausse(self):
        entetes = fixtures.signer("GET", "/v1/sante", secret=b"x" * 48)
        self.assertEqual(self.pont.traiter("GET", "/v1/sante", entetes, b"")[0], 401)

    def test_signature_d_une_autre_route(self):
        entetes = fixtures.signer("GET", "/v1/gateway")
        self.assertEqual(self.pont.traiter("GET", "/v1/sante", entetes, b"")[0], 401)

    def test_signature_couvre_la_requete_et_le_corps(self):
        entetes = fixtures.signer("GET", "/v1/consommation?jours=7")
        self.assertEqual(self.pont.traiter("GET", "/v1/consommation?jours=30", entetes, b"")[0], 401)
        corps = json.dumps({"job_id": "31cd7657668e"}).encode()
        entetes = fixtures.signer("POST", "/v1/actions/cron.run", corps)
        autre = json.dumps({"job_id": "7b72a4dcb9a6"}).encode()
        self.assertEqual(self.pont.traiter("POST", "/v1/actions/cron.run", entetes, autre)[0], 401)
        self.assertEqual(self.appels(), [])

    def test_fenetre_de_30_secondes(self):
        for decalage, attendu in ((-29, 200), (29, 200), (-31, 401), (31, 401), (-3600, 401)):
            with self.subTest(decalage=decalage):
                entetes = fixtures.signer("GET", "/v1/gateway", horodatage=int(time.time()) + decalage)
                self.assertEqual(self.pont.traiter("GET", "/v1/gateway", entetes, b"")[0], attendu)

    def test_rejeu_refuse_pour_les_actions(self):
        corps = json.dumps({"job_id": "31cd7657668e"}).encode()
        entetes = fixtures.signer("POST", "/v1/actions/cron.run", corps)
        self.assertEqual(self.pont.traiter("POST", "/v1/actions/cron.run", entetes, corps)[0], 200)
        statut, reponse = self.pont.traiter("POST", "/v1/actions/cron.run", entetes, corps)
        self.assertEqual(statut, 401)
        self.assertEqual(len(self.appels()), 1)

    def test_secret_trop_court_refuse(self):
        with self.assertRaises(ValueError):
            hp.Pont(self.home, b"court", self.bin)

    def test_fichier_secret_en_0600_obligatoire(self):
        chemin = os.path.join(self.tmp, "secret")
        with open(chemin, "wb") as f:
            f.write(fixtures.SECRET_PONT)
        os.chmod(chemin, 0o644)
        with self.assertRaises(SystemExit):
            hp.lire_secret(chemin)
        os.chmod(chemin, 0o600)
        self.assertEqual(hp.lire_secret(chemin), fixtures.SECRET_PONT)


class TestLectures(Base):
    def test_chaque_route_repond(self):
        for route in ROUTES:
            with self.subTest(route=route):
                statut, corps = self.get(route)
                self.assertEqual(statut, 200, corps)

    def test_sante(self):
        _, s = self.get("/v1/sante")
        self.assertTrue(s["ok"], [v for v in s["verifications"] if not v["ok"]])
        noms = {v["nom"] for v in s["verifications"]}
        self.assertNotIn("kanban.tableaux", noms)
        self.assertIn("state.db (dev).sessions", noms)

    def test_gateway(self):
        _, g = self.get("/v1/gateway")
        self.assertEqual(g["etat"], "running")
        self.assertTrue(g["processus_actif"])
        self.assertEqual(g["plateformes"][0]["nom"], "discord")
        self.assertEqual(g["plateformes"][0]["etat"], "connected")
        self.assertNotIn("argv", g)
        self.assertNotIn("hermes_home", json.dumps(g))

    def test_gateway_arrete(self):
        with open(os.path.join(self.home, "gateway_state.json")) as f:
            etat = json.load(f)
        etat["pid"] = 2 ** 22 + 12345
        with open(os.path.join(self.home, "gateway_state.json"), "w") as f:
            json.dump(etat, f)
        _, g = self.get("/v1/gateway")
        self.assertFalse(g["processus_actif"])

    def test_planifiees(self):
        _, c = self.get("/v1/cron")
        self.assertEqual(len(c["taches"]), 2)
        job = c["taches"][0]
        self.assertEqual(job["livraison"], "discord")
        self.assertNotIn("prompt", job)
        _, d = self.get("/v1/cron/31cd7657668e")
        self.assertEqual(len(d["historique"]), 1)
        self.assertEqual([s["nom"] for s in d["sorties"]], ["2026-10-08_08-01-28.md"])  # le lien est ignoré
        _, s = self.get("/v1/cron/31cd7657668e/sorties/2026-10-08_08-01-28.md")
        self.assertIn("# Veille", s["contenu"])
        self.assertNotIn("b3BlbnNzaC1rZXktdjEAAAAA", s["contenu"])
        self.assertEqual(self.get("/v1/cron/000000000000")[0], 404)
        self.assertEqual(self.get("/v1/cron/31cd7657668e/sorties/2026-10-08_09-00-00.md")[0], 404)

    def test_consommation(self):
        _, c = self.get("/v1/consommation?jours=7")
        self.assertEqual(len(c["par_jour"]), 7)
        # 2 profils × (2 sessions × 6 500 tokens + 1 session seule × 10 tokens)
        self.assertEqual(c["total"]["total"], 2 * (2 * 6500 + 10))
        self.assertEqual(c["total"]["sessions"], 6)
        self.assertEqual({p["profil"] for p in c["par_profil"]}, {"default", "dev"})
        self.assertEqual({m["modele"] for m in c["par_modele"]}, {"claude-opus-5-5", "claude-sonnet-5-5", "inconnu"})
        self.assertEqual(c["total"]["cout_reel_usd"], 0.0)
        self.assertEqual(self.get("/v1/consommation?jours=abc")[0], 422)

    def test_sessions_metadonnees_seulement(self):
        _, s = self.get("/v1/consommation/sessions")
        self.assertEqual(len(s["sessions"]), 6)
        brut = json.dumps(s)
        self.assertNotIn("PROMPT", brut)
        self.assertNotIn("message privé", brut)
        self.assertEqual(set(s["sessions"][0]), {
            "id", "profil", "source", "modele", "titre", "demarre_a", "termine_a", "derniere_activite",
            "duree_secondes", "fin",
            "messages", "outils", "tokens", "cout_estime_usd"})

    def test_poles(self):
        _, p = self.get("/v1/poles")
        dev = next(x for x in p["poles"] if x["pole"] == "dev")
        self.assertEqual(dev["lead"], "cto")
        self.assertEqual(dev["executants"], ["dev"])
        self.assertEqual(dev["auditeurs"], ["qa"])
        cto = next(x for x in p["profils"] if x["profil"] == "cto")
        self.assertEqual(cto["modele"], "claude-opus-5-5")
        self.assertEqual(cto["skills_actifs"], ["laravel-best-practices", "spike"])
        self.assertEqual(set(cto), {"profil", "pole", "role", "description", "modele", "skills_actifs"})
        defaut = next(x for x in p["profils"] if x["profil"] == "default")
        self.assertEqual(defaut["skills_actifs"], ["hermes-agent"])
        self.assertEqual(p["skills_communs"], ["nextcloud-files", "grounded-citations"])

    def test_erreurs(self):
        _, e = self.get("/v1/erreurs")
        self.assertEqual(e["comptes"], {"erreurs": 2, "avertissements": 1})
        self.assertEqual({x["profil"] for x in e["entrees"]}, {"default", "dev"})
        _, e72 = self.get("/v1/erreurs?heures=168")
        self.assertEqual(e72["comptes"]["erreurs"], 3)

    def test_sqlite_en_lecture_seule(self):
        import sqlite3
        with hp.ouvrir_ro(os.path.join(self.home, "state.db")) as c:
            with self.assertRaises(sqlite3.OperationalError):
                c.execute("DELETE FROM sessions")

    def test_aucun_select_etoile_dans_le_code(self):
        with open(hp.__file__, encoding="utf-8") as f:
            source = f.read()
        self.assertIsNone(re.search(r"(?i)select\s+(\w+\.)?\*", source))

    def test_methodes_refusees(self):
        for methode in ("PUT", "DELETE", "PATCH"):
            entetes = fixtures.signer(methode, "/v1/sante")
            self.assertIn(self.pont.traiter(methode, "/v1/sante", entetes, b"")[0], (404, 405))


class TestAucuneRouteVersUnSecret(Base):
    TENTATIVES = [
        "/v1/missions/..", "/v1/missions/../.env", "/v1/missions/%2e%2e/.env", "/v1/missions/%2e%2e%2f.env",
        "/v1/missions/%252e%252e/.env", "/v1/missions/2026-10-08-hermes-os/../../../.env",
        "/v1/missions/2026-10-08-hermes-os/%2e%2e/%2e%2e/%2e%2e/auth.json",
        "/v1/missions/2026-10-08-hermes-os/..%2f..%2f..%2f.env.md", "/v1/missions/2026-10-08-hermes-os/.env",
        "/v1/missions/2026-10-08-hermes-os/lien-env.md", "/v1/missions/2026-10-08-hermes-os/lien-dehors.md",
        "/v1/missions/2026-10-08-hermes-os/dossier-dehors/hors-hermes.md",
        "/v1/missions/2026-10-08-hermes-os/dossier-dehors/.config/secret.txt",
        "/v1/missions/lien-vault", "/v1/missions/lien-vault/items.json", "/v1/missions/vault",
        "/v1/missions/2026-10-08-hermes-os/%00.md", "/v1/missions/2026-10-08-hermes-os\\..\\..\\.env",
        "/v1/missions//etc/passwd", "/v1/missions/%2fetc%2fpasswd", "/v1/missions/~/.config",
        "/v1/missions/2026-10-08-hermes-os/%C0%AE%C0%AE/.env",
        "/v1/kanban", "/v1/kanban/dev", "/v1/kanban/dev/t_20e38ac9", "/v1/kanban/..%2f..%2f.env", "/v1/terminal",
        "/v1/cron/..%2f.env", "/v1/cron/31cd7657668e/sorties/..%2f..%2fjobs.json",
        "/v1/cron/31cd7657668e/sorties/2026-10-08_09-00-00.md",
        "/v1/.env", "/v1/auth.json", "/v1/vault", "/v1/pairing", "/v1/config", "/v1/../.env", "/.env", "/v2/sante",
        "/v1/sante/../../.env", "/v1/poles/cto", "/v1/profiles/dev/config.yaml", "/v1/state.db",
    ]

    def test_traversees_et_liens_refuses(self):
        for chemin in self.TENTATIVES:
            with self.subTest(chemin=chemin):
                statut, corps = self.get(chemin)
                self.assertIn(statut, (404, 422), corps)
                brut = json.dumps(corps)
                for appat in fixtures.APPATS:
                    self.assertNotIn(appat, brut)

    def test_aucun_appat_ni_secret_dans_aucune_reponse(self):
        for route in ROUTES:
            with self.subTest(route=route):
                statut, corps = self.get(route)
                brut = json.dumps(corps, ensure_ascii=False)
                for appat in fixtures.APPATS:
                    self.assertNotIn(appat, brut)
                for secret in fixtures.SECRETS_TEXTE:
                    self.assertNotIn(secret, brut)
                self.assertNotIn("hunter2", brut)
                self.assertNotIn("PRIVATE KEY-----\nb3", brut)
                self.assertNotIn("100000000000000001", brut)  # identifiant du salon de livraison

    def test_chemin_confine(self):
        with self.assertRaises(hp.ErreurHttp):
            hp.chemin_confine(self.home, "..", "dehors")
        with self.assertRaises(hp.ErreurHttp):
            hp.chemin_confine(self.home, "studio/missions/lien-vault")
        with self.assertRaises(hp.ErreurHttp):
            hp.chemin_confine(self.home, "profiles", "dev", ".env")
        self.assertTrue(hp.chemin_confine(self.home, "studio").endswith("/studio"))


class TestActions(Base):
    def test_cron(self):
        for action, verbe in (("cron.run", "run"), ("cron.pause", "pause"), ("cron.resume", "resume")):
            with self.subTest(action=action):
                statut, r = self.post(action, {"job_id": "31cd7657668e"})
                self.assertEqual(statut, 200, r)
                self.assertTrue(r["ok"])
                self.assertEqual(self.appels()[-1]["argv"], ["cron", verbe, "31cd7657668e"])
                self.assertEqual(self.appels()[-1]["stdin"], "")  # jamais le stdin du pont
                self.assertNotIn(fixtures.SECRETS_TEXTE[1], r["sortie"])
                self.assertIn(hp.MASQUE, r["sortie"])

    def test_environnement_minimal_transmis_a_la_cli(self):
        os.environ["HERMES_OS_PONT_TEST_FUITE"] = "ne-doit-pas-passer"
        try:
            self.post("cron.run", {"job_id": "31cd7657668e"})
        finally:
            del os.environ["HERMES_OS_PONT_TEST_FUITE"]
        appel = self.appels()[-1]
        self.assertEqual(appel["home"], os.path.realpath(self.home))
        self.assertNotIn("HERMES_OS_PONT_TEST_FUITE", appel["env"])

    def test_validation_stricte(self):
        refus = [
            ("cron.run", {"job_id": "31CD7657668E"}), ("cron.run", {"job_id": "31cd7657668"}),
            ("cron.run", {"job_id": "31cd7657668e; id"}), ("cron.run", {"job_id": ["31cd7657668e"]}),
            ("cron.run", {"job_id": "000000000000"}), ("cron.pause", {}),
        ]
        for action, donnees in refus:
            with self.subTest(action=action, donnees=str(donnees)[:60]):
                statut, r = self.post(action, donnees)
                self.assertEqual(statut, 422, r)
        self.assertEqual(self.appels(), [])

    def test_acteur_journalise(self):
        # L3 : l'e-mail du compte hermes-os à l'origine de l'action est journalisé par le pont, sans effet sur la CLI.
        with self.assertLogs("hermes-os-pont", level="INFO") as journal:
            statut, r = self.post("cron.run", {"job_id": "31cd7657668e", "acteur": "camille@exemple.fr"})
        self.assertEqual(statut, 200, r)
        self.assertEqual(self.appels()[-1]["argv"], ["cron", "run", "31cd7657668e"])
        self.assertIn("action cron.run demandée par camille@exemple.fr", "\n".join(journal.output))

    def test_acteur_invalide_ne_forge_pas_de_ligne(self):
        for valeur in ("a@b\nFAUX action ok", "sans-arobase", 42, None, "x" * 300 + "@b.fr"):
            with self.subTest(valeur=str(valeur)[:30]):
                with self.assertLogs("hermes-os-pont", level="INFO") as journal:
                    self.post("cron.run", {"job_id": "31cd7657668e", "acteur": valeur})
                self.assertIn("action cron.run demandée par inconnu", "\n".join(journal.output))
                self.assertNotIn("FAUX", "\n".join(journal.output))

    def test_actions_hors_liste(self):
        for action in ("kanban.create", "kanban.comment", "kanban.unblock", "kanban.archive", "kanban.complete", "cron.remove", "shell", "..", "cron.run;id"):
            with self.subTest(action=action):
                statut, _ = self.post(action, {"job_id": "31cd7657668e"})
                self.assertEqual(statut, 404)
        self.assertEqual(self.appels(), [])

    def test_json_invalide(self):
        chemin = "/v1/actions/cron.run"
        for corps in (b"pas du json", b"[1,2]", b"\xff\xfe"):
            statut, _ = self.pont.traiter("POST", chemin, fixtures.signer("POST", chemin, corps), corps)
            self.assertEqual(statut, 400)

    def test_corps_trop_gros(self):
        corps = b"{" + b" " * (hp.CORPS_MAX + 1) + b"}"
        chemin = "/v1/actions/cron.run"
        statut, _ = self.pont.traiter("POST", chemin, fixtures.signer("POST", chemin, corps), corps)
        self.assertEqual(statut, 413)

    def test_echec_de_la_cli(self):
        fixtures.faux_hermes(self.bin, self.journal, code=3)
        statut, r = self.post("cron.run", {"job_id": "31cd7657668e"})
        self.assertEqual(statut, 200)
        self.assertFalse(r["ok"])
        self.assertEqual(r["code"], 3)

    def test_delai_depasse(self):
        fixtures.faux_hermes(self.bin, self.journal, dormir=3)
        self.pont.delai_action = 0.5
        statut, r = self.post("cron.run", {"job_id": "31cd7657668e"})
        self.assertEqual(statut, 504)
        self.assertFalse(r["ok"])

    def test_cli_introuvable(self):
        self.pont.hermes_bin = os.path.join(self.tmp, "absent")
        statut, r = self.post("cron.run", {"job_id": "31cd7657668e"})
        self.assertEqual(statut, 503)

    def test_aucun_shell_dans_le_code(self):
        with open(hp.__file__, encoding="utf-8") as f:
            source = f.read()
        self.assertNotIn("shell=True", source)
        self.assertNotIn("os.system", source)
        self.assertNotIn("os.popen", source)
        self.assertEqual(len(re.findall(r"subprocess\.run\(", source)), 1)


class TestYaml(unittest.TestCase):
    def test_poles(self):
        y = hp.lire_yaml_simple(fixtures.POLES_YAML)
        self.assertEqual(y["commun_skills"], ["nextcloud-files", "grounded-citations"])
        self.assertEqual(y["profils"]["cto"]["keep"], ["laravel-best-practices", "github"])
        self.assertEqual(y["profils"]["cto"]["description"], "Lead tech / CTO du pôle Dev web : cadre le projet.")
        self.assertEqual(y["profils"]["qa"]["role"], "auditeur")

    def test_config_profil_ne_garde_que_modele_et_skills(self):
        extrait = hp.extraire_config_profil(fixtures.CONFIG_PROFIL)
        self.assertEqual(extrait, {"modele": "claude-opus-5-5", "desactives": {"github-désactivé", "pdf"}})


class TestServeurUnix(Base):
    def setUp(self):
        super().setUp()
        self.sock = os.path.join(self.tmp, "run", "pont.sock")
        self.serveur = hp.creer_serveur(self.pont, self.sock)
        self.fil = threading.Thread(target=self.serveur.serve_forever, daemon=True)
        self.fil.start()

    def tearDown(self):
        self.serveur.shutdown()
        self.serveur.server_close()
        super().tearDown()

    def requete(self, methode: str, chemin: str, corps: bytes = b"", signer: bool = True):
        class Connexion(http.client.HTTPConnection):
            def connect(this):
                this.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                this.sock.connect(self.sock)
        c = Connexion("pont")
        entetes = fixtures.signer(methode, chemin, corps) if signer else {}
        c.request(methode, chemin, body=corps or None, headers=entetes)
        r = c.getresponse()
        donnees = r.read()
        c.close()
        return r.status, r.getheader("Content-Type"), donnees

    def test_socket_0660_et_pas_de_tcp(self):
        mode = stat.S_IMODE(os.stat(self.sock).st_mode)
        self.assertEqual(mode, 0o660)
        self.assertTrue(stat.S_ISSOCK(os.stat(self.sock).st_mode))
        self.assertEqual(self.serveur.socket.family, socket.AF_UNIX)

    def test_http_de_bout_en_bout(self):
        statut, type_, corps = self.requete("GET", "/v1/sante")
        self.assertEqual(statut, 200)
        self.assertEqual(type_, "application/json; charset=utf-8")
        self.assertTrue(json.loads(corps)["ok"])
        self.assertEqual(self.requete("GET", "/v1/sante", signer=False)[0], 401)
        self.assertEqual(self.requete("PUT", "/v1/sante")[0], 405)
        corps = json.dumps({"job_id": "31cd7657668e"}).encode()
        statut, _, r = self.requete("POST", "/v1/actions/cron.pause", corps)
        self.assertEqual(statut, 200)
        self.assertEqual(self.appels()[-1]["argv"], ["cron", "pause", "31cd7657668e"])

    def test_refuse_d_ecraser_un_fichier_qui_n_est_pas_un_socket(self):
        faux = os.path.join(self.tmp, "fichier.sock")
        with open(faux, "w") as f:
            f.write("x")
        with self.assertRaises(SystemExit):
            hp.creer_serveur(self.pont, faux)
        self.assertTrue(os.path.isfile(faux))


if __name__ == "__main__":
    unittest.main()
