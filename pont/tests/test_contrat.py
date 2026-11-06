"""Le pont réel respecte pont/contrat.json (le DemoClient Laravel est vérifié contre le même fichier)."""
from __future__ import annotations

import json
import os
import sys
import unittest

ICI = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, ICI)

from test_pont import Base  # noqa: E402

with open(os.path.join(os.path.dirname(ICI), "contrat.json"), encoding="utf-8") as f:
    CONTRAT = json.load(f)

EXEMPLES = {
    "sante": "/v1/sante", "gateway": "/v1/gateway", "a_decider": "/v1/a-decider",
    "planifiees": "/v1/cron", "planifiee": "/v1/cron/31cd7657668e",
    "sortie": "/v1/cron/31cd7657668e/sorties/2026-10-08_08-01-28.md", "consommation": "/v1/consommation",
    "sessions": "/v1/consommation/sessions", "poles": "/v1/poles", "erreurs": "/v1/erreurs",
    "messagerie": "/v1/messagerie", "conversation_messagerie": "/v1/messagerie/c_0123456789abcdef",
    "vault": "/v1/vault",
}


def noeuds(donnees, chemin: str):
    """Objets désignés par un chemin du contrat (« », « x[] », « a.b », « x[].y »)."""
    courants = [donnees]
    for partie in [p for p in chemin.split(".") if p]:
        liste = partie.endswith("[]")
        cle = partie[:-2] if liste else partie
        suivants = []
        for c in courants:
            v = c[cle]
            suivants.extend(v if liste else [v])
        courants = suivants
    return courants


class TestContrat(Base):
    def setUp(self):
        super().setUp()
        from hermes_os_messagerie import Messagerie
        self.pont.messagerie = Messagerie(self.pont, os.path.join(self.tmp, "messagerie"))
        self.pont.messagerie._ajouter("c_0123456789abcdef", None,
                                      {"interlocuteur": "cto", "titre": "t", "cree_a": "2026-10-14T10:00:00+02:00"})
        self.pont.messagerie._ajouter("c_0123456789abcdef", {"type": "message", "auteur": "utilisateur", "texte": "x"})
        self.pont.messagerie._ajouter("c_0123456789abcdef", {"type": "message", "auteur": "manager",
                                                             "texte": "QUESTION : on publie ?"})
        import faux_coffre
        self.pont.coffre = faux_coffre.coffre(self.pont, os.path.join(self.tmp, "agent"))
        self.post("vault.create", {"profil": "default", "kind": "login", "label": "Malt", "origin": "https://www.malt.fr",
                                   "identifier_type": "email", "identifier": "v@exemple.fr", "secret": {"password": "mot-de-passe-de-test"}})

    def test_toutes_les_routes_sont_couvertes(self):
        self.assertEqual(set(EXEMPLES), set(CONTRAT["routes"]))

    def test_cles_exactes(self):
        for nom, formes in CONTRAT["routes"].items():
            statut, donnees = self.get(EXEMPLES[nom])
            self.assertEqual(statut, 200, (nom, donnees))
            for chemin, cles in formes.items():
                attendues = cles
                objets = noeuds(donnees, chemin)
                self.assertTrue(objets, f"{nom} : « {chemin} » vide dans la fixture, contrat non vérifié")
                for objet in objets:
                    with self.subTest(route=nom, chemin=chemin):
                        self.assertEqual(sorted(objet), sorted(attendues))


if __name__ == "__main__":
    unittest.main()
