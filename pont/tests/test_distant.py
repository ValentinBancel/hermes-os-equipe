"""Mode distant du pont (L4) : signature, permissions, URL, relier/delier, boucle contre une fausse console.
    python3 -m unittest discover -s pont/tests -v
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import io
import json
import os
import stat
import sys
import threading
import time
import unittest
from contextlib import redirect_stderr, redirect_stdout
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from unittest import mock

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(ICI))
sys.path.insert(0, ICI)

import hermes_os_distant as hd  # noqa: E402
import hermes_os_pont as hp  # noqa: E402
from test_pont import Base  # noqa: E402

SECRET = base64.urlsafe_b64encode(b"s" * 36).decode()  # 48 car. base64url, construit (test_depot)
INSTANCE = "0b6a7c1e-1111-4222-8333-944455556666"


class FausseConsole:
    """Console minimale : vérifie la signature et le rejeu comme la vraie (R5), journalise les appels."""

    def __init__(self, code="ABCD2345"):
        self.code = code
        self.revoquee = False
        self.nonces: set[str] = set()
        self.appels: list[tuple[str, str, dict | None]] = []
        self.actions: list[dict] = []
        self.resultats: dict[str, dict] = {}
        self.releves: list[dict] = []
        console = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def _corps(self) -> bytes:
                n = int(self.headers.get("Content-Length") or 0)
                return self.rfile.read(n) if n else b""

            def _rep(self, statut, donnees=None):
                corps = b"" if donnees is None else json.dumps(donnees).encode()
                self.send_response(statut)
                self.send_header("Content-Length", str(len(corps)))
                self.end_headers()
                self.wfile.write(corps)

            def _signee(self, corps: bytes) -> bool:
                h = self.headers
                if console.revoquee or h.get("X-Hermes-Instance") != INSTANCE:
                    return False
                ts, nonce = h.get("X-Hermes-Horodatage", ""), h.get("X-Hermes-Nonce", "")
                if abs(time.time() - int(ts or 0)) > 300 or nonce in console.nonces or len(nonce) != 32:
                    return False
                attendu = hmac.new(SECRET.encode(), "\n".join(
                    [self.command, self.path, ts, nonce, hashlib.sha256(corps).hexdigest()]).encode(),
                    hashlib.sha256).hexdigest()
                if not hmac.compare_digest(attendu, h.get("X-Hermes-Signature", "")):
                    return False
                console.nonces.add(nonce)
                return True

            def do_POST(self):  # noqa: N802
                corps = self._corps()
                donnees = json.loads(corps) if corps else None
                console.appels.append(("POST", self.path, donnees))
                if self.path == "/pont/v1/appairage":
                    if donnees.get("code") != console.code:
                        return self._rep(422, {"erreur": "Code refusé."})
                    console.code = None
                    return self._rep(201, {"instance_id": INSTANCE, "secret": SECRET})
                if not self._signee(corps):
                    return self._rep(401, {"erreur": "Non authentifié."})
                if self.path == "/pont/v1/releves":
                    console.releves.append(donnees)
                    return self._rep(204)
                if self.path.startswith("/pont/v1/actions/") and self.path.endswith("/resultat"):
                    console.resultats[self.path.split("/")[4]] = donnees
                    return self._rep(204)
                return self._rep(404)

            def do_GET(self):  # noqa: N802
                console.appels.append(("GET", self.path, None))
                if not self._signee(b""):
                    return self._rep(401, {"erreur": "Non authentifié."})
                if self.path.startswith("/pont/v1/actions?attente="):
                    if console.actions:
                        a, console.actions = console.actions, []
                        return self._rep(200, {"actions": a})
                    time.sleep(0.05)
                    return self._rep(204)
                return self._rep(404)

        self.serveur = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.url = f"http://127.0.0.1:{self.serveur.server_address[1]}"
        threading.Thread(target=self.serveur.serve_forever, daemon=True).start()

    def fermer(self):
        self.serveur.shutdown()
        self.serveur.server_close()


class TestValidation(unittest.TestCase):
    def test_signature_conforme_a_R5(self):
        corps = b'{"a":1}'
        attendu = hmac.new(SECRET.encode(), b"POST\n/pont/v1/releves?x=1\n1700000000\n" + b"ab" * 16 + b"\n"
                           + hashlib.sha256(corps).hexdigest().encode(), hashlib.sha256).hexdigest()
        self.assertEqual(hd.signer(SECRET, "post", "/pont/v1/releves?x=1", "1700000000", "ab" * 16, corps), attendu)
        self.assertNotEqual(hd.signer(SECRET, "POST", "/pont/v1/releves", "1700000000", "ab" * 16, b"{}"), attendu)

    def test_code_normalise(self):
        self.assertEqual(hd.normaliser_code(" abcd-2345\n"), "ABCD2345")
        for faux in ("ABCD-234", "ABCD-23O5", "ABCD-2341", "", "ABCD-23456"):  # O, 1 hors alphabet
            with self.assertRaises(hd.ErreurDistant):
                hd.normaliser_code(faux)

    def test_http_refuse_hors_bouclage(self):
        self.assertEqual(hd.url_console("https://console.exemple.fr/", {}), "https://console.exemple.fr")
        for ok in ("http://localhost:8080", "http://127.0.0.1", "http://127.8.9.10/", "http://[::1]:18080"):
            self.assertTrue(hd.url_console(ok, {}).startswith("http://"))
        for refus in ("http://console.exemple.fr", "http://10.0.0.1", "ftp://x", "https://" + "a:b" + "@x.fr", "x.fr",
                      "https://x.fr/?q=1"):
            with self.assertRaises(hd.ErreurDistant, msg=refus):
                hd.url_console(refus, {})
        with self.assertLogs("hermes-os-pont", "WARNING"):
            self.assertEqual(hd.url_console("http://console.exemple.fr", {"HERMES_OS_PONT_HTTP_NON_SUR": "1"}),
                             "http://console.exemple.fr")


class TestInstance(Base):
    def test_ecriture_en_0600_dans_un_dossier_0700(self):
        chemin = hd.ecrire_instance(self.home, {"url": "https://c.fr", "instance_id": INSTANCE, "secret": SECRET})
        self.assertEqual(stat.S_IMODE(os.stat(chemin).st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(os.stat(os.path.dirname(chemin)).st_mode), 0o700)
        self.assertEqual(hd.lire_instance(self.home)["instance_id"], INSTANCE)

    def test_refus_de_demarrer_si_trop_ouvert(self):
        chemin = hd.ecrire_instance(self.home, {"url": "https://c.fr", "instance_id": INSTANCE, "secret": SECRET})
        os.chmod(chemin, 0o644)
        with self.assertRaisesRegex(hd.ErreurDistant, "trop ouvert"):
            hd.lire_instance(self.home)
        os.chmod(chemin, 0o600)
        os.chmod(os.path.dirname(chemin), 0o755)
        with self.assertRaisesRegex(hd.ErreurDistant, "trop ouvert"):
            hd.lire_instance(self.home)

    def test_lien_symbolique_refuse(self):
        dossier = hd.dossier_instance(self.home)
        os.makedirs(dossier, mode=0o700)
        cible = os.path.join(self.dehors, "instance.json")
        with open(cible, "w") as f:
            json.dump({"url": "https://c.fr", "instance_id": INSTANCE, "secret": SECRET}, f)
        os.chmod(cible, 0o600)
        os.symlink(cible, hd.chemin_instance(self.home))
        with self.assertRaises(hd.ErreurDistant):
            hd.lire_instance(self.home)

    def test_sans_instance(self):
        with self.assertRaisesRegex(hd.ErreurDistant, "relier"):
            hd.lire_instance(self.home)

    def test_sans_instance_cite_le_chemin_cherche(self):
        """qa m3 : le message dit où l'identité est cherchée (et --hermes-home si le home est ailleurs)."""
        with self.assertRaises(hd.ErreurDistant) as e:
            hd.lire_instance(self.home)
        self.assertIn(hd.chemin_instance(self.home), str(e.exception))
        self.assertIn("--hermes-home", str(e.exception))


class TestAvecConsole(Base):
    def setUp(self):
        super().setUp()
        self.console = FausseConsole()
        self.addCleanup(self.console.fermer)
        self.unite = os.path.join(self.tmp, "config")
        os.environ["XDG_CONFIG_HOME"] = self.unite
        self.addCleanup(os.environ.pop, "XDG_CONFIG_HOME", None)

    def _relier(self):
        return hd.relier(self.console.url, "abcd-2345", "atelier", self.home, ["default", "dev"], hp.VERSION)

    def test_relier_ecrit_l_instance_sans_jamais_afficher_le_secret(self):
        sortie = io.StringIO()
        with redirect_stdout(sortie):
            code = hp.main(["relier", self.console.url, "--hermes-home", self.home, "--code", "ABCD-2345",
                            "--sans-systemd", "--sans-coffre", "--nom", "atelier"])
        self.assertEqual(code, 0)
        self.assertNotIn(SECRET, sortie.getvalue())
        self.assertIn("relié", sortie.getvalue())
        inst = hd.lire_instance(self.home)
        self.assertEqual((inst["instance_id"], inst["nom"]), (INSTANCE, "atelier"))
        envoi = self.console.appels[0][2]
        self.assertEqual(envoi["code"], "ABCD2345")
        self.assertIn("dev", envoi["profils"])
        self.assertEqual(envoi["version"], hp.VERSION)

    def test_code_refuse(self):
        self.console.code = "ZZZZ2222"
        with self.assertRaisesRegex(hd.ErreurDistant, "Code refusé"):
            self._relier()
        self.assertFalse(os.path.exists(hd.chemin_instance(self.home)))

    def test_etat_puis_delier(self):
        self._relier()
        sortie = io.StringIO()
        with redirect_stdout(sortie):
            hp.main(["etat", "--hermes-home", self.home])
        etat = json.loads(sortie.getvalue())
        self.assertTrue(etat["relie"])
        self.assertNotIn("secret", etat)
        with redirect_stdout(io.StringIO()):
            self.assertEqual(hp.main(["delier", "--hermes-home", self.home]), 0)
        self.assertFalse(os.path.exists(hd.chemin_instance(self.home)))
        self.assertFalse(hd.etat(self.home)["relie"])

    @staticmethod
    def _systemctl(appels, actif=False):
        """Faux systemctl : `is-active` répond 0 si une unité du même nom tourne déjà, 3 sinon (comme systemd)."""
        def executer(argv, **_):
            appels.append(argv)
            code = (0 if actif else 3) if "is-active" in argv else 0
            return type("R", (), {"returncode": code})()
        return executer

    def test_unite_systemd(self):
        appels = []
        chemin = hd.installer_systemd("/opt/pont/hermes_os_pont.py", self.home, ["--messagerie"],
                                      executer=self._systemctl(appels))
        self.assertTrue(chemin.startswith(self.unite))
        with open(chemin) as f:
            unite = f.read()
        self.assertIn('ExecStart=', unite)
        self.assertIn('"/opt/pont/hermes_os_pont.py" "distant" "--messagerie"', unite)
        self.assertIn(f'Environment="HERMES_HOME={self.home}"', unite)
        self.assertNotIn(SECRET, unite)
        self.assertEqual(appels[-1], ["systemctl", "--user", "enable", "--now", hd.SERVICE])

    def test_unite_deja_active_ni_remplacee_ni_annoncee_installee(self):
        """Risque qa L5 : un pont du même nom tourne déjà → rien n'est écrit ni démarré, message explicite."""
        appels = []
        with self.assertRaises(hd.ErreurDistant) as e:
            hd.installer_systemd("/opt/pont/hermes_os_pont.py", self.home, [],
                                 executer=self._systemctl(appels, actif=True))
        message = str(e.exception)
        self.assertIn(f"{hd.SERVICE} est déjà active", message)
        self.assertIn(f"systemctl --user disable --now {hd.SERVICE}", message)
        self.assertIn("--sans-systemd", message)
        self.assertFalse(os.path.exists(hd.chemin_unite()))
        self.assertEqual(appels, [["systemctl", "--user", "is-active", "--quiet", hd.SERVICE]])

    def test_relier_avec_unite_deja_active_ne_dit_pas_service_installe(self):
        appels = []
        sortie, erreurs = io.StringIO(), io.StringIO()
        with mock.patch.object(hd.subprocess, "run", self._systemctl(appels, actif=True)), \
                redirect_stdout(sortie), redirect_stderr(erreurs):
            code = hp.main(["relier", self.console.url, "--hermes-home", self.home, "--code", "ABCD-2345",
                            "--sans-coffre", "--nom", "atelier"])
        self.assertEqual(code, 2)
        self.assertNotIn("Service installé", sortie.getvalue())
        self.assertIn("déjà active", erreurs.getvalue())
        self.assertIn("--sans-systemd", erreurs.getvalue())
        self.assertNotIn(SECRET, sortie.getvalue() + erreurs.getvalue())
        self.assertFalse(any("enable" in a or "daemon-reload" in a for a in appels))
        # Vérifié avant l'appairage : ni identité écrite, ni code consommé.
        self.assertFalse(os.path.exists(hd.chemin_instance(self.home)))
        self.assertEqual(appels, [["systemctl", "--user", "is-active", "--quiet", hd.SERVICE]])

    def test_relier_sans_systemd_ignore_une_unite_active(self):
        appels = []
        sortie = io.StringIO()
        with mock.patch.object(hd.subprocess, "run", self._systemctl(appels, actif=True)), redirect_stdout(sortie):
            code = hp.main(["relier", self.console.url, "--hermes-home", self.home, "--code", "ABCD-2345",
                            "--sans-coffre", "--nom", "atelier", "--sans-systemd"])
        self.assertEqual(code, 0)
        self.assertIn("relié", sortie.getvalue())
        self.assertEqual(appels, [])
        self.assertTrue(os.path.exists(hd.chemin_instance(self.home)))

    def test_unite_systemd_echappe_dollar_et_pourcent(self):
        """secu m7 : `$` et `%` sont doublés, systemd ne les développe pas."""
        unite = hd.unite_systemd("/opt/p$HOME/50%/hermes_os_pont.py", "/srv/h$x%n", ["--nom", "a$b%c"])
        self.assertIn('"/opt/p$$HOME/50%%/hermes_os_pont.py" "distant" "--nom" "a$$b%%c"', unite)
        self.assertIn('Environment="HERMES_HOME=/srv/h$$x%%n"', unite)

    def test_redirection_jamais_suivie(self):
        """secu m2 : une 302 n'est pas suivie, la cible ne reçoit aucune requête signée."""
        cible = FausseConsole()
        self.addCleanup(cible.fermer)
        redirige = self.console.url

        class R(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_POST(self):  # noqa: N802
                self.send_response(302)
                self.send_header("Location", cible.url + "/pont/v1/releves")
                self.send_header("Content-Length", "0")
                self.end_headers()

        serveur = ThreadingHTTPServer(("127.0.0.1", 0), R)
        threading.Thread(target=serveur.serve_forever, daemon=True).start()
        self.addCleanup(serveur.server_close)
        self.addCleanup(serveur.shutdown)
        self.assertTrue(redirige)
        console = hd.Console(f"http://127.0.0.1:{serveur.server_address[1]}", INSTANCE, SECRET)
        statut, _ = console.requete("POST", "/pont/v1/releves", {"releves": {}})
        self.assertEqual(statut, 302)
        self.assertEqual(cible.appels, [])

    def test_action_trop_vieille_non_executee(self):
        """secu m4 : une action créée il y a plus de 90 s rend 408 sans passer par le pont local."""
        boucle = self._boucle()
        vieille = (hd.dt.datetime.now(hd.dt.timezone.utc) - hd.dt.timedelta(seconds=91)).isoformat()
        recente = (hd.dt.datetime.now(hd.dt.timezone.utc) - hd.dt.timedelta(seconds=5)).isoformat()
        self.console.actions = [
            {"id": "v1", "action": "cron.run", "donnees": {"job_id": "31cd7657668e"}, "cree_le": vieille},
            {"id": "r1", "action": "lecture", "donnees": {"chemin": "/v1/sante"}, "cree_le": recente},
        ]
        boucle.tourner(tours=2)
        self.assertEqual(self.console.resultats["v1"], {"ok": False, "code": 408, "message": "Action expirée."})
        self.assertTrue(self.console.resultats["r1"]["ok"])
        if os.path.exists(self.journal):
            with open(self.journal) as f:
                self.assertNotIn("31cd7657668e", f.read())

    def test_trop_vieille(self):
        maintenant = hd.dt.datetime(2026, 11, 3, 12, 0, 0, tzinfo=hd.dt.timezone.utc)
        self.assertTrue(hd.trop_vieille("2026-11-03T11:58:29+00:00", maintenant))
        self.assertFalse(hd.trop_vieille("2026-11-03T11:58:31+00:00", maintenant))
        self.assertFalse(hd.trop_vieille("2026-11-03T12:59:00+01:00", maintenant))  # fuseau pris en compte
        self.assertFalse(hd.trop_vieille(None, maintenant))
        self.assertFalse(hd.trop_vieille("pas une date", maintenant))

    def _boucle(self):
        self._relier()
        inst = hd.lire_instance(self.home)
        return hd.Boucle(self.pont, hd.Console(inst["url"], inst["instance_id"], inst["secret"]), "atelier",
                         hp.VERSION, intervalle=60)

    def test_boucle_releves_actions_et_acteur(self):
        boucle = self._boucle()
        self.console.actions = [
            {"id": "a1", "action": "cron.run", "donnees": {"job_id": "31cd7657668e"}, "acteur": "val@exemple.fr"},
            {"id": "a2", "action": "lecture", "donnees": {"chemin": "/v1/cron/31cd7657668e"}},
            {"id": "a3", "action": "lecture", "donnees": {"chemin": "/v1/../../.env"}},
            {"id": "a4", "action": "rm -rf", "donnees": {}},
        ]
        with self.assertLogs("hermes-os-pont", "INFO") as journaux:
            self.assertEqual(boucle.tourner(tours=2), 0)
        # relevés : premier envoi + après les actions, clés en liste blanche, données du pont local
        self.assertGreaterEqual(len(self.console.releves), 2)
        r = self.console.releves[0]
        self.assertEqual(r["nom"], "atelier")
        self.assertEqual(r["releves"]["/v1/sante"]["statut"], 200)
        self.assertIn("/v1/consommation?jours=30", r["releves"])
        self.assertTrue(any(k.startswith("/v1/cron/") for k in r["releves"]))
        self.assertIsInstance(self.console.releves[1]["latence_ms"], int)
        # actions : passées par la CLI, acteur journalisé par le pont (C7)
        self.assertTrue(self.console.resultats["a1"]["ok"])
        with open(self.journal) as f:
            self.assertIn("31cd7657668e", f.read())
        self.assertTrue(any("val@exemple.fr" in m for m in journaux.output))
        self.assertTrue(self.console.resultats["a2"]["ok"])
        self.assertIn("historique", self.console.resultats["a2"]["donnees"])
        self.assertFalse(self.console.resultats["a3"]["ok"])
        self.assertFalse(self.console.resultats["a4"]["ok"])

    def test_instance_deliee_arret_propre(self):
        boucle = self._boucle()
        self.console.revoquee = True
        with self.assertLogs("hermes-os-pont", "WARNING") as journaux:
            self.assertEqual(boucle.tourner(tours=3), 0)
        self.assertTrue(boucle.revoquee)
        self.assertTrue(any("délié" in m for m in journaux.output))
        self.assertEqual(len([a for a in self.console.appels if a[0] == "GET"]), 0)

    def test_rejeu_refuse_par_la_console(self):
        """Deux requêtes n'ont jamais le même nonce : un rejeu exact est refusé (401)."""
        boucle = self._boucle()
        boucle.pousser()
        boucle.pousser()
        self.assertEqual(len(self.console.nonces), 2)

    def test_mode_socket_toujours_disponible(self):
        statut, d = self.pont.traiter("GET", "/v1/sante", {}, b"")
        self.assertEqual(statut, 401)  # sans signature locale
        self.assertEqual(self.pont.executer("GET", "/v1/sante")[0], 200)


class TestSansPolesYaml(Base):
    def test_un_pole_par_profil(self):
        self.pont.poles_chemin = None
        poles = self.pont.poles()
        noms = {p["pole"] for p in poles["poles"]}
        self.assertIn("default", noms)
        self.assertIn("dev", noms)
        self.assertNotIn("poles.yaml", json.dumps(self.pont.sante()))


if __name__ == "__main__":
    unittest.main()
