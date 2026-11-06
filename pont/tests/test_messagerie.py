"""Messagerie du pont (ADR 0008) : argv exact, liste blanche, garde-fou approbations, masquage, arrêt, stockage.
    python3 -m unittest discover -s pont/tests -v
"""
from __future__ import annotations

import json
import os
import stat
import sys
import time

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

import fixtures  # noqa: E402
from hermes_os_messagerie import Messagerie, masquer  # noqa: E402
from test_pont import Base  # noqa: E402

import unittest  # noqa: E402

NEXTCLOUD = "abcde-fghij-klmno-pqrst-uvwxy"
ENTROPIE = "Zq8Xv3Lk9Rt2Wp7Hn4Bd6Ym1Cf5Sg0Ja"  # 33 car.
HASH = "a7a56c9d3f1e2b4c5d6e7f8091a2b3c4d5e6f708"
SESSION = "20261014_100000_abcdef"

FAUX = r'''#!/usr/bin/env python3
import json, os, subprocess, sys, time
entree = sys.stdin.read()
with open(%(journal)r, "a", encoding="utf-8") as f:
    f.write(json.dumps({"argv": sys.argv[1:], "stdin": entree, "env": sorted(os.environ)}) + "\n")
mode = %(mode)r
def sortie(o):
    print(json.dumps(o), flush=True)
sortie({"type": "system", "subtype": "init", "session_id": %(session)r})
if mode == "dormir":
    time.sleep(60)
elif mode == "coupe":
    sortie({"type": "text", "text": "voici : ghp_A1b2C3d4E5f6G7h8"})
    sortie({"type": "text", "text": "I9j0K1l2M3n4O5p6Q7r8 fin"})
    sys.exit(1)
elif mode == "setsid":
    p = subprocess.Popen(["setsid", "sleep", "300"])
    open(%(pidfile)r, "w").write(str(p.pid))
    time.sleep(60)
else:
    if mode == "gros":
        sys.stdout.write("x" * (8 * 1024 * 1024) + "\n"); sys.stdout.flush()
    sortie({"type": "text", "text": "Bonjour " + %(nc)r})
    sortie({"type": "tool_use", "name": "read_file", "input": {"path": "/srv/hermes/profiles/assistant/.env"}})
    sortie({"type": "tool_result", "output": "SORTIE-OUTIL-SECRETE " + %(nc)r})
    if mode == "bloque":
        sortie({"type": "tool_result", "output": "BLOCKED: approval required"})
    sortie({"type": "result", "exit_code": 0,
            "text": "Reponse " + %(nc)r + " " + %(ent)r + " t_deadbeef c_0123456789abcdef " + %(h)r,
            "tokens": {"input": 4, "output": 10}})
'''


class BaseMsg(Base):
    mode = "ok"

    def setUp(self):
        super().setUp()
        self.pidfile = os.path.join(self.tmp, "pid")
        self.installer(self.mode)
        self.stock = os.path.join(self.tmp, "messagerie")
        self.msg = Messagerie(self.pont, self.stock, budget=30, marge=5, delai_kill=1.0)
        self.pont.messagerie = self.msg

    def installer(self, mode):
        src = FAUX % {"journal": self.journal, "mode": mode, "pidfile": self.pidfile, "session": SESSION,
                      "nc": NEXTCLOUD, "ent": ENTROPIE, "h": HASH}
        with open(self.bin, "w", encoding="utf-8") as f:
            f.write(src)
        os.chmod(self.bin, stat.S_IRWXU)

    def envoyer(self, texte="Bonjour", ident="cto", **plus):
        return self.post("messagerie.envoyer", {"interlocuteur": ident, "texte": texte, **plus})

    def attendre(self, conv, delai=20):
        fin = time.time() + delai
        while time.time() < fin:
            s, d = self.get(f"/v1/messagerie/{conv}")
            if s == 200 and not d["en_cours"] and d["conversation"]["statut"] != "en_cours":
                return d
            time.sleep(0.1)
        self.fail("run non terminé")

    def ajouter_config(self, profil, texte):
        with open(os.path.join(self.home, "profiles", profil, "config.yaml"), "a", encoding="utf-8") as f:
            f.write("\n" + texte + "\n")


class TestListeBlanche(BaseMsg):
    def test_interlocuteurs(self):
        s, d = self.get("/v1/messagerie")
        self.assertEqual(s, 200)
        ids = {i["id"] for i in d["interlocuteurs"]}
        self.assertTrue(ids <= {"hermes", "cto"} and "cto" in ids, ids)
        self.assertFalse(ids & {"infra", "dev", "qa", "skillscout"})

    def test_refus(self):
        for ident in ("infra", "dev", "../default", "default", "qa", "cto\n", "", None):
            with self.subTest(ident=ident):
                self.assertEqual(self.envoyer(ident=ident)[0], 422)
        self.assertEqual(self.appels(), [])

    def test_option_interlocuteurs(self):
        m = Messagerie(self.pont, self.stock, interlocuteurs=["hermes"])
        self.assertLessEqual(set(m.interlocuteurs()), {"hermes"})

    def test_desactivee_par_defaut(self):
        self.pont.messagerie = None
        self.assertEqual(self.get("/v1/messagerie")[0], 404)
        self.assertEqual(self.envoyer()[0], 404)

    def test_signature_obligatoire(self):
        s, _ = self.pont.traiter("POST", "/v1/actions/messagerie.envoyer", {}, b"{}")
        self.assertIn(s, (401, 403))


class TestArgv(BaseMsg):
    def test_argv_exact_et_stdin(self):
        texte = "--yolo -p default $(id) `id`; rm -rf /"
        s, d = self.envoyer(texte)
        self.assertEqual(s, 202)
        self.attendre(d["conversation"])
        appel = self.appels()[0]
        self.assertEqual(appel["argv"], ["-p", "cto", "chat", "--query-file", "-", "--format", "stream-json",
                                         "--source", "hermes-os", "--run-budget", "30", "--max-turns", "90"])
        self.assertEqual(appel["stdin"], texte)

    def test_reprise_resume_un_seul_argument(self):
        s, d = self.envoyer()
        self.attendre(d["conversation"])
        self.assertEqual(self.envoyer("Et ensuite ?", conversation=d["conversation"])[0], 202)
        self.attendre(d["conversation"])
        argv = self.appels()[1]["argv"]
        self.assertIn(f"--resume={SESSION}", argv)
        self.assertNotIn("--resume", argv)

    def test_env_en_liste_blanche(self):
        os.environ["HERMES_YOLO_MODE"] = "1"
        try:
            s, d = self.envoyer()
            self.attendre(d["conversation"])
        finally:
            del os.environ["HERMES_YOLO_MODE"]
        self.assertNotIn("HERMES_YOLO_MODE", self.appels()[0]["env"])

    def test_validation(self):
        for texte in ("", "   ", "x" * 8001, 12, None):
            self.assertEqual(self.envoyer(texte)[0], 422)
        for conv in ("c_0123456789abcdef\n", "-c_0123456789abcdef", "../x", "c_0123456789abcdeg"):
            with self.subTest(conv=conv):
                self.assertEqual(self.envoyer(conversation=conv)[0], 422)
        self.assertEqual(self.appels(), [])


class TestGardeFou(BaseMsg):
    def refuse(self, texte):
        self.ajouter_config("cto", texte)
        s, d = self.envoyer()
        self.assertEqual(s, 409, d)
        self.assertEqual(self.appels(), [])

    def test_mode_off(self):
        self.refuse("approvals:\n  mode: off")

    def test_single_query_approve(self):
        self.refuse("approvals:\n  single_query_mode: approve")

    def test_hooks_auto_accept(self):
        self.refuse("hooks_auto_accept: true")

    def test_liste_blanche_refus(self):
        cas = ["approvals:\n  mode: false", "approvals:\n  mode: off", "approvals:\n  mode: yolo",
               "approvals:\n  mode: true", "approvals:\n  single_query_mode: allow",
               "approvals:\n  single_query_mode: yes", "approvals:\n  single_query_mode: off",
               "approvals:\n  single_query_mode: always", "approvals: {mode: off}", "approvals: &a\n  mode: manual",
               "hooks_auto_accept: yes", "hooks_auto_accept: on", "hooks_auto_accept: 1", "hooks_auto_accept: maybe"]
        for texte in cas:
            with self.subTest(texte=texte):
                chemin = os.path.join(self.home, "profiles", "cto", "config.yaml")
                avant = open(chemin, encoding="utf-8").read()
                try:
                    self.ajouter_config("cto", texte)
                    self.assertEqual(self.envoyer(f"essai {texte}")[0], 409)
                    self.assertEqual(self.appels(), [])
                finally:
                    open(chemin, "w", encoding="utf-8").write(avant)

    def test_liste_blanche_accepte(self):
        for texte in ("approvals:\n  mode: smart", "approvals:\n  mode: manual", "approvals:\n  single_query_mode: deny",
                      "hooks_auto_accept: false", ""):
            with self.subTest(texte=texte):
                chemin = os.path.join(self.home, "profiles", "cto", "config.yaml")
                avant = open(chemin, encoding="utf-8").read()
                try:
                    self.ajouter_config("cto", texte)
                    self.assertIsNone(self.msg.garde_approbations("cto"))
                finally:
                    open(chemin, "w", encoding="utf-8").write(avant)

    def test_lecteur_strict_echec_ferme(self):
        """Sans PyYAML (R1 L4) : toute construction non comprise sous une clé gardée est refusée (409)."""
        for texte in ("approvals:\n  mode: *a", "approvals:\n  mode: !!str manual", "approvals:\n  mode:\n    x: 1",
                      "approvals:\n  mode: manual\n  mode: off", "approvals:\n  mode: manual\napprovals:\n  mode: off",
                      "<<: *defaut", "? approvals\n: {mode: off}", "approvals:\n  - mode", "approvals:\n\tmode: off",
                      "hooks:\n  auto_accept: |\n    true", "approvals:\n  mode: manual\n---\napprovals:\n  mode: off",
                      "approvals:\n  mode: manual\n    single_query_mode: allow", '"approvals":\n  mode: "off"'):
            with self.subTest(texte=texte):
                chemin = os.path.join(self.home, "profiles", "cto", "config.yaml")
                avant = open(chemin, encoding="utf-8").read()
                try:
                    self.ajouter_config("cto", texte)
                    self.assertIsNotNone(self.msg.garde_approbations("cto"))
                finally:
                    open(chemin, "w", encoding="utf-8").write(avant)

    def test_lecteur_strict_accepte_le_reste_du_fichier(self):
        import hermes_os_messagerie as m
        y = m.lire_garde_approbations("model:\n  default: x  # c\nliste: [a, b]\nbloc: |\n  approvals: off\n"
                                      "approvals:\n  deny:\n    - '*tmux*'\n  mode: 'smart'\n  liste: [a]\n  single_query_mode: deny\n"
                                      "hooks_auto_accept: no\n")
        self.assertEqual(y["approvals"]["mode"], "smart")
        self.assertEqual(y["approvals"]["single_query_mode"], "deny")
        self.assertIs(y["hooks_auto_accept"], False)

    def test_yaml_illisible_et_alerte_sante(self):
        with open(os.path.join(self.home, "profiles", "cto", "config.yaml"), "wb") as f:
            f.write(b"approvals:\n  mode: sm\xff\x00")
        self.assertEqual(self.envoyer()[0], 409)
        _, sante = self.get("/v1/sante")
        self.assertFalse(sante["ok"])

    def test_accepte(self):
        s, d = self.envoyer()
        self.assertEqual(s, 202, d)
        self.attendre(d["conversation"])


class TestMasquageEtStockage(BaseMsg):
    def test_masquage_reponse_et_jsonl(self):
        s, d = self.envoyer()
        res = self.attendre(d["conversation"])
        brut = json.dumps(res, ensure_ascii=False)
        with open(os.path.join(self.stock, d["conversation"] + ".jsonl"), encoding="utf-8") as f:
            jsonl = f.read()
        for contenu in (brut, jsonl):
            for secret in (NEXTCLOUD, ENTROPIE, "SORTIE-OUTIL-SECRETE"):
                self.assertNotIn(secret, contenu)
        for ok in ("t_deadbeef", "c_0123456789abcdef", HASH):
            self.assertIn(ok, brut)

    def test_etape_fichier_protege(self):
        s, d = self.envoyer()
        res = self.attendre(d["conversation"])
        etapes = [e["texte"] for e in res["evenements"] if e["type"] == "etape"]
        self.assertEqual(etapes, ["lit un fichier protégé"])

    def test_masquer_unitaire(self):
        for secret in (NEXTCLOUD, ENTROPIE, "sk-" + "ant-api03-abcdefghijklmnopqrstuvwxyz"):
            self.assertNotIn(secret, masquer(f"x {secret} y"))
        for ok in ("t_deadbeef", "c_0123456789abcdef", "20261014_165608_84e15c", HASH):
            self.assertEqual(masquer(ok), ok)

    def test_permissions_et_audit_sans_contenu(self):
        texte = "confidentiel " * 15
        s, d = self.envoyer(texte)
        self.attendre(d["conversation"])
        self.assertEqual(stat.S_IMODE(os.stat(self.stock).st_mode), 0o700)
        for nom in os.listdir(self.stock):
            if os.path.isfile(os.path.join(self.stock, nom)):
                self.assertEqual(stat.S_IMODE(os.stat(os.path.join(self.stock, nom)).st_mode), 0o600, nom)
        with open(os.path.join(self.stock, "audit.jsonl"), encoding="utf-8") as f:
            audit = f.read()
        self.assertNotIn("confidentiel", audit)
        self.assertEqual(json.loads(audit)["longueur"], len(texte.strip()))

    def test_archive_et_traversee(self):
        s, d = self.envoyer()
        self.attendre(d["conversation"])
        self.assertEqual(self.post("messagerie.archiver", {"conversation": d["conversation"]})[0], 200)
        self.assertTrue(os.path.isfile(os.path.join(self.stock, "archive", d["conversation"] + ".jsonl")))
        self.assertEqual(self.get(f"/v1/messagerie/{d['conversation']}")[0], 404)
        self.assertEqual(self.post("messagerie.archiver", {"conversation": "../x"})[0], 422)
        self.assertIn(self.get("/v1/messagerie/c_0123456789abcdeg")[0], (404, 422))


class TestMasquageCoupe(BaseMsg):
    mode = "coupe"

    def test_appat_coupe_en_deux(self):
        s, d = self.envoyer()
        res = self.attendre(d["conversation"])
        with open(os.path.join(self.stock, d["conversation"] + ".jsonl"), encoding="utf-8") as f:
            jsonl = f.read()
        for contenu in (json.dumps(res), jsonl):
            self.assertNotIn("ghp_" + "A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8", contenu)
            self.assertNotIn("A1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q7r8", contenu)


class TestGmailContexte(unittest.TestCase):
    def test_contexte_seulement(self):
        self.assertNotIn("abcd efgh ijkl mnop", masquer("mot de passe d'application : abcd efgh ijkl mnop"))
        self.assertEqual(masquer("une phrase avec plus de quatre mots courts"), "une phrase avec plus de quatre mots courts")
        self.assertEqual(masquer("nous irons bien dans mon jeux"), "nous irons bien dans mon jeux")


class TestStockage(BaseMsg):
    def test_lien_symbolique_refuse_et_volume(self):
        s, d = self.envoyer()
        self.attendre(d["conversation"])
        cible = os.path.join(self.tmp, "cible")
        open(cible, "w").write("x")
        os.symlink(cible, os.path.join(self.stock, "c_0123456789abcdef.jsonl"))
        with self.assertRaises(OSError):
            self.msg._ajouter("c_0123456789abcdef", {"type": "statut", "statut": "x"})
        self.assertEqual(open(cible).read(), "x")
        import hermes_os_messagerie as hm
        ancien = hm.STOCKAGE_ALERTE_OCTETS
        hm.STOCKAGE_ALERTE_OCTETS = 10
        try:
            self.assertTrue(any("stockage" in a for a in self.msg.alertes()))
        finally:
            hm.STOCKAGE_ALERTE_OCTETS = ancien


class TestUnitPont(unittest.TestCase):
    def test_interlocuteurs_dans_unit(self):
        with open(os.path.join(ICI, "..", "hermes-os-pont.service"), encoding="utf-8") as f:
            unit = f.read()
        self.assertIn("--interlocuteurs hermes,assistant,recherche,redaction", unit)


class TestApprobation(BaseMsg):
    mode = "bloque"

    def test_approbation_requise(self):
        s, d = self.envoyer()
        res = self.attendre(d["conversation"])
        msgs = [e for e in res["evenements"] if e["type"] == "message" and e["auteur"] == "manager"]
        self.assertTrue(msgs[0].get("approbation_requise"))
        _, a = self.get("/v1/a-decider")
        self.assertEqual([(e["type"], e["conversation"]) for e in a["elements"]],
                         [("approbation", d["conversation"])])


class TestADecider(BaseMsg):
    def conv(self, cid, ident, *messages):
        self.msg._ajouter(cid, None, {"interlocuteur": ident, "titre": "t", "cree_a": "2026-11-03T10:00:00+01:00"})
        for auteur, texte in messages:
            self.msg._ajouter(cid, {"type": "message", "auteur": auteur, "texte": texte})

    def test_questions_du_manager_seulement(self):
        self.conv("c_000000000000000a", "cto", ("utilisateur", "Salut"), ("manager", "Je fais quoi ?"))
        self.conv("c_000000000000000b", "cto", ("manager", "Analyse.\nQUESTION : on publie lundi\nCONTEXTE : x"))
        self.conv("c_000000000000000c", "cto", ("manager", "Fait."))
        self.conv("c_000000000000000d", "cto", ("manager", "Je fais quoi ?"), ("utilisateur", "Rien."))
        self.conv("c_000000000000000e", "dev", ("manager", "Une question ?"))  # hors liste blanche
        _, a = self.get("/v1/a-decider")
        self.assertEqual([(e["type"], e["conversation"]) for e in a["elements"]],
                         [("question", "c_000000000000000a"), ("question", "c_000000000000000b")])
        self.assertEqual(a["elements"][0]["nom"], self.msg.interlocuteurs()["cto"]["nom"])

    def test_vide_sans_messagerie(self):
        self.pont.messagerie = None
        self.assertEqual(self.get("/v1/a-decider"), (200, {"elements": []}))


class TestGrosseLigne(BaseMsg):
    mode = "gros"

    def test_ligne_geante_ignoree(self):
        s, d = self.envoyer()
        res = self.attendre(d["conversation"], delai=30)
        self.assertEqual(res["conversation"]["statut"], "termine")


class TestRuns(BaseMsg):
    mode = "dormir"

    def test_un_run_par_conversation_et_arret(self):
        s, d = self.envoyer()
        self.assertEqual(s, 202)
        conv = d["conversation"]
        time.sleep(0.5)
        self.assertEqual(self.envoyer("bis", conversation=conv)[0], 409)
        self.assertEqual(self.post("messagerie.arreter", {"conversation": conv})[0], 200)
        self.assertEqual(self.attendre(conv)["conversation"]["statut"], "arrete")
        self.assertEqual(self.post("messagerie.arreter", {"conversation": conv, "n": 2})[0], 409)

    def test_cinquieme_run_refuse(self):
        convs = []
        for i in range(4):
            s, d = self.envoyer(f"question {i}")
            self.assertEqual(s, 202)
            convs.append(d["conversation"])
        self.assertEqual(self.envoyer()[0], 409)
        for c in convs:
            self.post("messagerie.arreter", {"conversation": c})
        for c in convs:
            self.attendre(c)

    def test_chien_de_garde(self):
        self.pont.messagerie = Messagerie(self.pont, os.path.join(self.tmp, "m2"), budget=1, marge=0.5,
                                          delai_kill=1.0)
        s, d = self.envoyer()
        self.assertEqual(self.attendre(d["conversation"])["conversation"]["statut"], "delai_depasse")


class TestDescendants(BaseMsg):
    mode = "setsid"

    def test_petit_fils_setsid_tue(self):
        s, d = self.envoyer()
        fin = time.time() + 5
        while not os.path.exists(self.pidfile) and time.time() < fin:
            time.sleep(0.1)
        time.sleep(0.3)
        pid = int(open(self.pidfile).read())
        self.post("messagerie.arreter", {"conversation": d["conversation"]})
        self.attendre(d["conversation"])
        fin, vivant = time.time() + 6, True
        while time.time() < fin and vivant:
            vivant = os.path.exists(f"/proc/{pid}") and not Messagerie._zombie(pid)
            time.sleep(0.2)
        self.assertFalse(vivant)


if __name__ == "__main__":
    unittest.main()
