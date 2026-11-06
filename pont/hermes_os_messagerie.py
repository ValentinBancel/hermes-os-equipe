"""Messagerie du pont hermes-os (ADR 0008, décision A) : la console parle à un manager Hermes.

Le pont lance `hermes -p <profil> chat --query-file - --format stream-json …` (liste d'arguments, sans shell,
texte sur stdin), ne garde de la sortie qu'un fil filtré et masqué, et le stocke en JSONL.
Stdlib uniquement. Activé seulement par l'option --messagerie du pont.
"""
from __future__ import annotations

import datetime as dt
import json
import os
import re
import shutil
import signal
import stat
import subprocess
import threading
import time
import unicodedata
from collections.abc import Iterator

import hermes_os_pont as hp

# Garde d'approbation (ADR 0008, S2) : lecteur strict stdlib (R1 L4, plus de PyYAML). Il ne lit que les
# clés de premier niveau `approvals`, `hooks` (un niveau d'enfants) et `hooks_auto_accept` ; toute
# construction qu'il ne comprend pas à cet endroit (flux, ancre, alias, étiquette, fusion `<<`, clé
# complexe, bloc littéral, doublon, tabulation) lève ValueError → échec fermé.
CLES_GARDE = ("approvals", "hooks", "hooks_auto_accept")
ENFANTS_GARDES = ("mode", "single_query_mode", "hooks_auto_accept", "auto_accept")
RE_CLE_YAML = re.compile(r"""^(?:"([A-Za-z0-9_.-]+)"|'([A-Za-z0-9_.-]+)'|([A-Za-z0-9_.-]+))[ \t]*:(?:[ \t]+(.*))?$""")
VRAIS_YAML, FAUX_YAML = ("true", "yes", "on", "y"), ("false", "no", "off", "n")


def _valeur_garde(brute: str):
    v = brute.strip()
    if not v:
        return None
    if v[0] in "&*!{[|>@`%" or v.startswith("<<"):
        raise ValueError("construction YAML non admise")
    if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"'":
        if "\\" in v:
            raise ValueError("échappement non admis")
        return v[1:-1]
    if v[0] in "\"'":
        raise ValueError("chaîne non fermée")
    bas = v.lower()
    if bas in ("~", "null"):
        return None
    if bas in VRAIS_YAML:
        return True
    if bas in FAUX_YAML:
        return False
    return v


def lire_garde_approbations(texte: str) -> dict:
    racine: dict = {}
    bloc: dict | None = None
    indent_bloc: int | None = None
    documents = 0
    sous_garde = False
    for brute in texte.lstrip("\ufeff").splitlines():
        ligne = hp._sans_commentaire(brute)
        if not ligne.strip():
            continue
        indent = len(ligne) - len(ligne.lstrip(" "))
        if ligne[indent:indent + 1] == "\t":
            raise ValueError("tabulation")
        contenu = ligne.strip()
        if indent == 0:
            bloc, indent_bloc = None, None
            if contenu.startswith("---"):
                documents += 1
                if documents > 1 or contenu != "---":
                    raise ValueError("plusieurs documents")
                continue
            if contenu[0] in "%?-&*!{[|>" or contenu.startswith("..."):
                raise ValueError("construction de premier niveau non admise")
            m = RE_CLE_YAML.match(contenu)
            if not m:
                if contenu.startswith(("<<", '"', "'")) or ":" not in contenu:
                    raise ValueError("clé de premier niveau non analysable")
                continue  # autre clé (caractères exotiques) : hors garde
            cle = m.group(1) or m.group(2) or m.group(3)
            if cle not in CLES_GARDE:
                continue
            if cle in racine:
                raise ValueError("clé en double")
            valeur = m.group(4)
            if cle == "hooks_auto_accept":
                racine[cle] = _valeur_garde(valeur or "")
            elif valeur and valeur.strip():
                v = _valeur_garde(valeur)
                if v is not None:
                    racine[cle] = v  # scalaire là où un bloc est attendu : l'évaluateur refuse
                    continue
                racine[cle] = None
            else:
                bloc = {}
                racine[cle] = bloc
            continue
        if bloc is None:
            continue  # contenu d'une clé hors garde
        if indent_bloc is None:
            indent_bloc = indent
        if indent > indent_bloc:
            if sous_garde:
                raise ValueError("bloc imbriqué sous une clé gardée")
            continue  # contenu d'un enfant non gardé (ex. approvals.deny : liste)
        if indent < indent_bloc or contenu.startswith(("-", "?", "<<")):
            raise ValueError("structure non admise sous une clé gardée")
        m = RE_CLE_YAML.match(contenu)
        if not m:
            raise ValueError("ligne non analysable sous une clé gardée")
        cle = m.group(1) or m.group(2) or m.group(3)
        if cle in bloc:
            raise ValueError("clé en double")
        sous_garde = cle in ENFANTS_GARDES
        bloc[cle] = _valeur_garde(m.group(4) or "") if sous_garde else "(non gardé)"
        if sous_garde and bloc[cle] is None:
            raise ValueError("valeur vide sous une clé gardée")
    return {k: ({} if v is None and k != "hooks_auto_accept" else v) for k, v in racine.items()}

RE_CONVERSATION = re.compile(r"c_[a-f0-9]{16}")
RE_SESSION = re.compile(r"\d{8}_\d{6}_[a-f0-9]{6}")
RE_OUTIL = re.compile(r"[a-z0-9_]{1,40}")
TEXTE_MAX = 8000
LIGNE_MAX = 1024 * 1024          # une ligne de sortie plus grosse est ignorée
JSONL_MAX = 5 * 1024 * 1024      # au-delà : la conversation est refusée (409)
TEXTE_MANAGER_MAX = 64 * 1024
RUNS_MAX = 4
CONVERSATIONS_MAX = 200
BUDGET_SECONDES = 900
MARGE_SECONDES = 60              # chien de garde = budget + marge (960 s)
DELAI_KILL = 5.0
STOCKAGE_ALERTE_OCTETS = 500 * 1024 * 1024
PROFILS_REFUSES = {"infra", "dev"}   # jamais joignables par la messagerie, même s'ils sont « lead »

# Secrets propres à la messagerie (en plus de hp.masquer).
_MOTIFS_MESSAGERIE = [
    re.compile(r"\b[A-Za-z0-9]{5}(?:-[A-Za-z0-9]{5}){4}\b"),            # mot de passe d'application Nextcloud
]
# Mot de passe d'application Gmail (4×4 lettres) : masqué seulement près d'un mot-clé (≤ 40 car.), pas dans la prose.
_GMAIL_CONTEXTE = re.compile(r"((?:mot de passe|mdp|password|passwd|app)[^\n]{0,40}?)\b[a-z]{4}(?: [a-z]{4}){3}\b", re.I)
_HAUTE_ENTROPIE = re.compile(r"(?<![A-Za-z0-9_+=-])[A-Za-z0-9_+=-]{32,}(?![A-Za-z0-9_+=-])")
_HASH_GIT = re.compile(r"[0-9a-f]{40}")


def _remplacer_entropie(m: re.Match) -> str:
    v = m.group(0)
    if _HASH_GIT.fullmatch(v) or RE_SESSION.fullmatch(v):
        return v
    if v.startswith(("t_", "c_")) and re.fullmatch(r"[tc]_[a-f0-9]{8,32}", v):
        return v
    if not (re.search(r"\d", v) and re.search(r"[A-Za-z]", v)):
        return v  # un mot très long, pas un secret
    return hp.MASQUE


def masquer(texte: str | None) -> str | None:
    """hp.masquer + formats de mots de passe d'application + chaînes à haute entropie (≥ 32 car.)."""
    if texte is None:
        return None
    texte = hp.masquer(texte)
    for motif in _MOTIFS_MESSAGERIE:
        texte = motif.sub(hp.MASQUE, texte)
    texte = _GMAIL_CONTEXTE.sub(lambda m: m.group(1) + hp.MASQUE, texte)
    return _HAUTE_ENTROPIE.sub(_remplacer_entropie, texte)


def maintenant() -> str:
    return dt.datetime.now(hp.FUSEAU).isoformat(timespec="seconds")


def nettoyer_texte(texte) -> str:
    if not isinstance(texte, str):
        raise hp.ErreurHttp(422, "Texte invalide.")
    propre = "".join(c for c in texte if c in "\n\t" or unicodedata.category(c) != "Cc").strip()
    if not 1 <= len(propre) <= TEXTE_MAX:
        raise hp.ErreurHttp(422, f"Le message doit faire de 1 à {TEXTE_MAX} caractères.")
    return propre


def _valider_conversation(valeur) -> str:
    if not isinstance(valeur, str) or not RE_CONVERSATION.fullmatch(valeur):
        raise hp.ErreurHttp(422, "Conversation invalide.")
    return valeur


def _protege(chemin: str) -> bool:
    for nom in chemin.replace("\\", "/").split("/"):
        n = nom.lower()
        if n in hp.NOMS_INTERDITS or n.startswith(".env"):
            return True
    return False


def etape_depuis_outil(nom, entree) -> str:
    """Une ligne, construite depuis le NOM de l'outil (et le nom de base d'un fichier) : jamais d'argument."""
    nom = nom if isinstance(nom, str) and RE_OUTIL.fullmatch(nom) else None
    if nom in ("read_file", "write_file", "patch", "search_files"):
        if nom == "search_files":
            return "cherche dans les fichiers"
        chemin = ""
        if isinstance(entree, dict):
            for cle in ("path", "file_path", "filepath"):
                if isinstance(entree.get(cle), str):
                    chemin = entree[cle]
                    break
        verbe = "lit" if nom == "read_file" else "modifie"
        if _protege(chemin):
            return f"{verbe} un fichier protégé"
        base = os.path.basename(chemin.rstrip("/")) or "un fichier"
        return f"{verbe} {masquer(base)[:80]}"
    return f"utilise : {nom}" if nom else "utilise : un outil"


def _ouvrir_ajout(chemin: str) -> int:
    """Ouverture en ajout, 0600, sans suivre de lien symbolique ; refuse tout ce qui n'est pas un fichier ordinaire."""
    fd = os.open(chemin, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        if not stat.S_ISREG(os.fstat(fd).st_mode):
            raise OSError("fichier non ordinaire")
        os.fchmod(fd, 0o600)
    except OSError:
        os.close(fd)
        raise
    return fd


def lignes_bornees(flux) -> Iterator[bytes]:
    """Lit ligne à ligne sans jamais garder plus de LIGNE_MAX octets (une ligne plus grosse est jetée)."""
    while True:
        ligne = flux.readline(LIGNE_MAX + 1)
        if not ligne:
            return
        if len(ligne) > LIGNE_MAX:
            while not ligne.endswith(b"\n"):
                ligne = flux.readline(65536)
                if not ligne:
                    return
            continue
        yield ligne


def _descendants(pid: int) -> list[int]:
    enfants: dict[int, list[int]] = {}
    try:
        for nom in os.listdir("/proc"):
            if not nom.isdigit():
                continue
            try:
                with open(f"/proc/{nom}/stat", "rb") as f:
                    contenu = f.read().decode("utf-8", "replace")
                ppid = int(contenu.rsplit(")", 1)[1].split()[1])
            except (OSError, ValueError, IndexError):
                continue
            enfants.setdefault(ppid, []).append(int(nom))
    except OSError:
        return []
    res, pile = [], [pid]
    while pile:
        courant = pile.pop()
        for e in enfants.get(courant, []):
            res.append(e)
            pile.append(e)
    return res


def _envoyer_signal(pids: list[int], sig: int) -> None:
    for p in pids:
        try:
            os.kill(p, sig)
        except OSError:
            pass


class Run:
    def __init__(self, conversation: str, interlocuteur: str):
        self.conversation = conversation
        self.interlocuteur = interlocuteur
        self.processus: subprocess.Popen | None = None
        self.brut = ""                   # texte du manager non masqué, borné ; jamais exposé
        self.brouillon = ""
        self.etapes: list[str] = []
        self.arrete = False
        self.delai_depasse = False
        self.debut = time.monotonic()


class Messagerie:
    def __init__(self, pont, stockage: str, interlocuteurs: list[str] | None = None,
                 budget: int = BUDGET_SECONDES, marge: float = MARGE_SECONDES, delai_kill: float = DELAI_KILL,
                 alerte_cout_jour: float | None = None):
        self.pont = pont
        self.stockage = os.path.abspath(stockage)
        self.autorises = set(interlocuteurs) if interlocuteurs else None
        self.budget, self.marge, self.delai_kill = budget, marge, delai_kill
        self.alerte_cout_jour = alerte_cout_jour
        self._runs: dict[str, Run] = {}
        self._verrou = threading.Lock()
        self._verrous_conv: dict[str, threading.Lock] = {}
        self._compteurs: dict[str, int] = {}
        os.makedirs(self.stockage, mode=0o700, exist_ok=True)
        os.chmod(self.stockage, 0o700)
        self._marquer_interrompus()

    # -- interlocuteurs ---------------------------------------------------------
    def _config_profil(self, profil: str) -> str:
        return self.pont._p("config.yaml") if profil == "default" else self.pont._p("profiles", profil, "config.yaml")

    def interlocuteurs(self) -> dict[str, dict]:
        """id public → {nom, pole, profil}. Leads de poles.yaml existants + hermes (default), sans infra/dev."""
        try:
            profils = self.pont._poles_yaml().get("profils") or {}
        except (OSError, ValueError):
            profils = {}
        existants = set(self.pont.profils_existants())
        res: dict[str, dict] = {}
        res["hermes"] = {"nom": "Hermes", "pole": None, "profil": "default"}
        for nom, decl in profils.items():
            if not isinstance(decl, dict) or decl.get("role") != "lead":
                continue
            if nom in PROFILS_REFUSES or nom not in existants or not hp.RE_PROFIL.match(nom):
                continue
            res[nom] = {"nom": nom, "pole": decl.get("pole"), "profil": nom}
        if self.autorises is not None:
            res = {k: v for k, v in res.items() if k in self.autorises}
        return res

    def _modele(self, profil: str) -> str | None:
        try:
            with open(self._config_profil(profil), encoding="utf-8", errors="replace") as f:
                return hp.extraire_config_profil(f.read())["modele"]
        except (OSError, hp.ErreurHttp):
            return None

    def garde_approbations(self, profil: str) -> str | None:
        """None si le profil est sûr ; sinon la raison du refus (409). Liste blanche stricte (ADR 0008, S2) :
        approvals.mode absent ou manual|smart ; single_query_mode absent ou deny ; hooks_auto_accept absent ou false.
        Toute autre valeur (booléen, yes/on/1, inconnue, style flux, ancre) est refusée."""
        illisible = "configuration du profil illisible : impossible de vérifier les approbations"
        try:
            with open(self._config_profil(profil), "rb") as f:
                texte = f.read().decode("utf-8")
        except FileNotFoundError:
            return None
        except (OSError, UnicodeDecodeError, hp.ErreurHttp):
            return illisible
        if "\x00" in texte:
            return illisible
        try:
            # Contrôle textuel en plus du lecteur strict : style flux / ancre / alias sur `approvals:`.
            if re.search(r"^approvals[ \t]*:[ \t]*[^\s#]", texte, re.M):
                return "configuration du profil non analysable : approbations en style flux ou avec ancre"
            y = lire_garde_approbations(texte)
        except Exception:  # noqa: BLE001
            return illisible
        if not isinstance(y, dict):
            return illisible
        return self._evaluer_approbations(y)

    @staticmethod
    def _evaluer_approbations(y: dict) -> str | None:
        inconnu = "configuration non analysable : valeur d'approbation inattendue ({})"
        appr = y.get("approvals", {})
        if appr is None:
            appr = {}
        if not isinstance(appr, dict):
            return inconnu.format("approvals")
        if "mode" in appr:
            m = appr["mode"]
            if not isinstance(m, str) or m.strip().lower() not in ("manual", "smart"):
                if isinstance(m, str) and m.strip().lower() == "off" or m is False:
                    return "approbations désactivées (mode off) sur ce profil"
                return inconnu.format("approvals.mode")
        if "single_query_mode" in appr:
            q = appr["single_query_mode"]
            if not isinstance(q, str) or q.strip().lower() != "deny":
                return "approbations automatiques actives sur ce profil" if str(q).lower() in (
                    "approve", "allow", "yes", "off", "true", "on", "1") else inconnu.format("approvals.single_query_mode")
        hooks = y.get("hooks", {})
        candidats = [("hooks_auto_accept", y), ("approvals.hooks_auto_accept", appr)]
        if isinstance(hooks, dict):
            candidats += [("hooks.hooks_auto_accept", hooks), ("hooks.auto_accept", hooks)]
        for nom, d in candidats:
            cle = nom.rsplit(".", 1)[-1]
            if cle in d and d[cle] is not False and not (isinstance(d[cle], str) and d[cle].strip().lower() == "false"):
                return "acceptation automatique des hooks active sur ce profil" if str(d[cle]).lower() in (
                    "true", "yes", "on", "1") else inconnu.format(nom)
        return None

    def alertes(self) -> list[str]:
        res = []
        for ident, info in self.interlocuteurs().items():
            raison = self.garde_approbations(info["profil"])
            if raison:
                res.append(f"{ident} : {raison}")
        volume = self._volume_stockage()
        if volume > STOCKAGE_ALERTE_OCTETS:
            res.append(f"stockage de la messagerie : {volume // (1024 * 1024)} Mio (seuil {STOCKAGE_ALERTE_OCTETS // (1024 * 1024)} Mio)")
        if self.alerte_cout_jour:
            jetons = self._jetons_du_jour()
            if jetons > self.alerte_cout_jour:
                res.append(f"consommation du jour : {jetons} jetons (seuil {int(self.alerte_cout_jour)})")
        return res

    def _volume_stockage(self) -> int:
        total = 0
        for racine, _, fichiers in os.walk(self.stockage):
            for nom in fichiers:
                try:
                    total += os.lstat(os.path.join(racine, nom)).st_size
                except OSError:
                    pass
        return total

    def _jetons_du_jour(self) -> int:
        jour = dt.datetime.now(hp.FUSEAU).strftime("%Y-%m-%d")
        total = 0
        try:
            with open(os.path.join(self.stockage, "audit.jsonl"), encoding="utf-8") as f:
                for ligne in f:
                    try:
                        e = json.loads(ligne)
                    except ValueError:
                        continue
                    if str(e.get("a", "")).startswith(jour):
                        total += int(e.get("jetons") or 0)
        except OSError:
            pass
        return total

    # -- stockage ---------------------------------------------------------------
    def _chemin(self, conversation: str, archive: bool = False) -> str:
        _valider_conversation(conversation)
        base = os.path.join(self.stockage, "archive") if archive else self.stockage
        return os.path.join(base, conversation + ".jsonl")

    def _verrou_conv(self, conversation: str) -> threading.Lock:
        with self._verrou:
            return self._verrous_conv.setdefault(conversation, threading.Lock())

    def _ajouter(self, conversation: str, evenement: dict | None, entete: dict | None = None) -> dict | None:
        chemin = self._chemin(conversation)
        with self._verrou_conv(conversation):
            nouveau = not os.path.exists(chemin)
            fd = _ouvrir_ajout(chemin)
            try:
                if nouveau:
                    self._compteurs[conversation] = 0
                    os.write(fd, (json.dumps({"type": "entete", **(entete or {})}, ensure_ascii=False) + "\n").encode())
                if evenement is None:
                    os.fsync(fd)
                    return None
                if conversation not in self._compteurs:
                    self._compteurs[conversation] = self._compter(chemin)
                self._compteurs[conversation] += 1
                evenement = {"n": self._compteurs[conversation], "a": maintenant(), **evenement}
                os.write(fd, (json.dumps(evenement, ensure_ascii=False) + "\n").encode())
                os.fsync(fd)
                return evenement
            finally:
                os.close(fd)

    @staticmethod
    def _compter(chemin: str) -> int:
        n = 0
        with open(chemin, "rb") as f:
            for _ in f:
                n += 1
        return max(0, n - 1)

    def _entete(self, chemin: str) -> dict | None:
        try:
            with open(chemin, "rb") as f:
                premiere = f.readline(65536)
            d = json.loads(premiere)
            return d if isinstance(d, dict) and d.get("type") == "entete" else None
        except (OSError, ValueError):
            return None

    def _dernier_statut(self, chemin: str) -> str:
        """Dernier événement `statut`, lu dans la fin du fichier seulement."""
        try:
            taille = os.path.getsize(chemin)
            with open(chemin, "rb") as f:
                f.seek(max(0, taille - 8192))
                fin = f.read().decode("utf-8", "replace").splitlines()
            for ligne in reversed(fin):
                try:
                    e = json.loads(ligne)
                except ValueError:
                    continue
                if isinstance(e, dict) and e.get("type") == "statut":
                    return str(e.get("statut"))
        except OSError:
            pass
        return "termine"

    def _marquer_interrompus(self) -> None:
        for nom in os.listdir(self.stockage):
            if nom.endswith(".jsonl") and RE_CONVERSATION.fullmatch(nom[:-6]):
                c = nom[:-6]
                if self._dernier_statut(self._chemin(c)) == "en_cours":
                    self._ajouter(c, {"type": "statut", "statut": "interrompu"})

    def _resume(self, conversation: str, chemin: str, entete: dict) -> dict:
        run = self._runs.get(conversation)
        statut = "en_cours" if run else self._dernier_statut(chemin)
        if statut == "en_cours" and not run:
            statut = "interrompu"
        try:
            modifie = dt.datetime.fromtimestamp(os.path.getmtime(chemin), hp.FUSEAU).isoformat(timespec="seconds")
        except OSError:
            modifie = entete.get("cree_a")
        return {"id": conversation, "interlocuteur": entete.get("interlocuteur"), "titre": entete.get("titre"),
                "cree_a": entete.get("cree_a"), "modifie_a": modifie, "statut": statut}

    def _conversations(self) -> list[dict]:
        res = []
        for nom in sorted(os.listdir(self.stockage)):
            if not (nom.endswith(".jsonl") and RE_CONVERSATION.fullmatch(nom[:-6])):
                continue
            chemin = os.path.join(self.stockage, nom)
            entete = self._entete(chemin)
            if entete:
                res.append(self._resume(nom[:-6], chemin, entete))
        res.sort(key=lambda c: c["modifie_a"] or "", reverse=True)
        return res

    # -- lectures ---------------------------------------------------------------
    def liste(self) -> dict:
        interl = self.interlocuteurs()
        actifs: dict[str, int] = {}
        for r in list(self._runs.values()):
            actifs[r.interlocuteur] = actifs.get(r.interlocuteur, 0) + 1
        return {
            "interlocuteurs": [{"id": i, "nom": v["nom"], "pole": v["pole"], "modele": self._modele(v["profil"]),
                                "en_cours": actifs.get(i, 0) > 0} for i, v in interl.items()],
            "conversations": [c for c in self._conversations() if c["interlocuteur"] in interl],
        }

    def _dernier_message(self, chemin: str) -> dict | None:
        """Dernier événement `message`, lu dans la fin du fichier seulement (256 Kio)."""
        try:
            taille = os.path.getsize(chemin)
            with open(chemin, "rb") as f:
                f.seek(max(0, taille - 256 * 1024))
                fin = f.read().decode("utf-8", "replace").splitlines()
        except OSError:
            return None
        for ligne in reversed(fin):
            try:
                e = json.loads(ligne)
            except ValueError:
                continue
            if isinstance(e, dict) and e.get("type") == "message":
                return e
        return None

    def a_decider(self) -> dict:
        """« À décider » de l'accueil : conversations dont le manager attend l'utilisateur (P2).

        - approbation : le dernier message du manager signale une action bloquée à confirmer ;
        - question : le dernier message du manager contient une ligne « QUESTION : … » ou finit par « ? ».
        Une conversation en cours ou dont le dernier message vient de l'utilisateur n'attend rien.
        """
        interl = self.interlocuteurs()
        elements = []
        for c in self._conversations():
            if c["interlocuteur"] not in interl or c["statut"] == "en_cours":
                continue
            m = self._dernier_message(self._chemin(c["id"]))
            if not m or m.get("auteur") != "manager" or not isinstance(m.get("texte"), str):
                continue
            texte = m["texte"].strip()
            if m.get("approbation_requise"):
                type_ = "approbation"
            elif re.search(r"^\W*QUESTION\s*:", texte, re.I | re.M) or texte.endswith("?"):
                type_ = "question"
            else:
                continue
            elements.append({"type": type_, "conversation": c["id"], "interlocuteur": c["interlocuteur"],
                             "nom": interl[c["interlocuteur"]]["nom"], "titre": c["titre"],
                             "texte": hp.tronquer(texte, 2000), "cree_a": m.get("a") or c["modifie_a"]})
        elements.sort(key=lambda e: e["cree_a"] or "")
        return {"elements": elements}

    def conversation(self, conversation: str, depuis: int = 0) -> dict:
        chemin = self._chemin(conversation)
        if not os.path.isfile(chemin):
            raise hp.ErreurHttp(404, "Conversation inconnue.")
        if os.path.getsize(chemin) > JSONL_MAX:
            raise hp.ErreurHttp(409, "Conversation trop volumineuse : à archiver.")
        entete = self._entete(chemin)
        if not entete:
            raise hp.ErreurHttp(404, "Conversation inconnue.")
        evenements = []
        with open(chemin, encoding="utf-8", errors="replace") as f:
            next(f, None)
            for ligne in f:
                try:
                    e = json.loads(ligne)
                except ValueError:
                    continue
                if isinstance(e, dict) and e.get("type") != "session" and e.get("n", 0) >= depuis:
                    evenements.append(e)
        run = self._runs.get(conversation)
        return {"conversation": self._resume(conversation, chemin, entete), "evenements": evenements,
                "brouillon": run.brouillon if run else "", "en_cours": run is not None,
                "etapes_en_cours": list(run.etapes[-50:]) if run else []}

    # -- actions ----------------------------------------------------------------
    def _audit(self, run: Run, texte_len: int, statut: str, jetons: int | None, duree: float) -> None:
        ligne = {"a": maintenant(), "interlocuteur": run.interlocuteur, "conversation": run.conversation,
                 "longueur": texte_len, "duree_s": round(duree, 1), "statut": statut, "jetons": jetons}
        fd = _ouvrir_ajout(os.path.join(self.stockage, "audit.jsonl"))
        try:
            os.write(fd, (json.dumps(ligne, ensure_ascii=False) + "\n").encode())
        finally:
            os.close(fd)

    def envoyer(self, donnees: dict) -> tuple[int, dict]:
        ident = donnees.get("interlocuteur")
        interl = self.interlocuteurs()
        if not isinstance(ident, str) or ident not in interl:
            raise hp.ErreurHttp(422, "Interlocuteur inconnu.")
        texte = nettoyer_texte(donnees.get("texte"))
        conversation = donnees.get("conversation")
        session = None
        if conversation is not None:
            _valider_conversation(conversation)
            chemin = self._chemin(conversation)
            entete = self._entete(chemin) if os.path.isfile(chemin) else None
            if not entete:
                raise hp.ErreurHttp(404, "Conversation inconnue.")
            if entete.get("interlocuteur") != ident:
                raise hp.ErreurHttp(422, "Cette conversation appartient à un autre interlocuteur.")
            if os.path.getsize(chemin) > JSONL_MAX:
                raise hp.ErreurHttp(409, "Conversation trop volumineuse : à archiver.")
            session = self._derniere_session(chemin)
        else:
            if sum(1 for c in self._conversations() if c["interlocuteur"] == ident) >= CONVERSATIONS_MAX:
                raise hp.ErreurHttp(409, "Trop de conversations avec cet interlocuteur : archivez-en.")
        profil = interl[ident]["profil"]
        raison = self.garde_approbations(profil)
        if raison:
            raise hp.ErreurHttp(409, raison[0].upper() + raison[1:] + ".")
        with self._verrou:
            if conversation and conversation in self._runs:
                raise hp.ErreurHttp(409, "Une réponse est déjà en cours dans cette conversation.")
            if len(self._runs) >= RUNS_MAX:
                raise hp.ErreurHttp(409, f"{RUNS_MAX} réponses déjà en cours.")
            creee = conversation is None
            if creee:
                import secrets
                conversation = "c_" + secrets.token_hex(8)
            run = Run(conversation, ident)
            self._runs[conversation] = run
        try:
            if creee:
                titre = masquer(texte.replace("\n", " ")[:60]) or ""
                self._ajouter(conversation, None, {"interlocuteur": ident, "titre": titre, "cree_a": maintenant()})
            self._ajouter(conversation, {"type": "message", "auteur": "utilisateur", "texte": masquer(texte)})
            self._ajouter(conversation, {"type": "statut", "statut": "en_cours"})
            cmd = [self.pont.hermes_bin, "-p", profil, "chat", "--query-file", "-", "--format", "stream-json",
                   "--source", "hermes-os", "--run-budget", str(self.budget), "--max-turns", "90"]
            if session:
                cmd.append(f"--resume={session}")
            env = {"PATH": "/usr/local/bin:/usr/bin:/bin:" + os.path.dirname(self.pont.hermes_bin),
                   "HOME": os.environ.get("HOME", "/nonexistent"), "LANG": "C.UTF-8", "HERMES_HOME": self.pont.home}
            run.processus = subprocess.Popen(
                cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, shell=False,
                env=env, cwd=self._cwd(), start_new_session=True)
        except Exception as e:  # noqa: BLE001
            with self._verrou:
                self._runs.pop(conversation, None)
            try:
                self._ajouter(conversation, {"type": "statut", "statut": "echec"})
            except OSError:
                pass
            if isinstance(e, hp.ErreurHttp):
                raise
            raise hp.ErreurHttp(503, f"CLI hermes non exécutable ({type(e).__name__}).") from None
        threading.Thread(target=self._suivre, args=(run, texte), daemon=True).start()
        return 202, {"conversation": conversation, "statut": "en_cours"}

    def _cwd(self) -> str:
        studio = os.path.join(self.pont.home, "studio")
        return studio if os.path.isdir(studio) else self.pont.home

    def _derniere_session(self, chemin: str) -> str | None:
        session = None
        with open(chemin, encoding="utf-8", errors="replace") as f:
            for ligne in f:
                try:
                    e = json.loads(ligne)
                except ValueError:
                    continue
                if isinstance(e, dict) and e.get("type") == "session" and isinstance(e.get("session"), str) \
                        and RE_SESSION.fullmatch(e["session"]):
                    session = e["session"]
        return session

    def _suivre(self, run: Run, texte: str) -> None:
        proc = run.processus
        assert proc is not None
        chien = threading.Timer(self.budget + self.marge, self._delai, args=(run,))
        chien.daemon = True
        chien.start()

        def ecrire():
            try:
                proc.stdin.write(texte.encode("utf-8"))
                proc.stdin.close()
            except OSError:
                pass
        threading.Thread(target=ecrire, daemon=True).start()
        final, code, jetons, approbation = None, None, None, False
        try:
            for brute in lignes_bornees(proc.stdout):
                try:
                    e = json.loads(brute)
                except ValueError:
                    continue
                if not isinstance(e, dict):
                    continue
                type_ = e.get("type")
                if type_ == "system" and e.get("subtype") == "init":
                    s = e.get("session_id")
                    if isinstance(s, str) and RE_SESSION.fullmatch(s):
                        self._ajouter(run.conversation, {"type": "session", "session": s})
                elif type_ == "text" and isinstance(e.get("text"), str):
                    if len(run.brut) < TEXTE_MANAGER_MAX:
                        run.brut = (run.brut + e["text"])[:TEXTE_MANAGER_MAX]
                        run.brouillon = masquer(run.brut)
                elif type_ == "tool_use":
                    etape = etape_depuis_outil(e.get("name"), e.get("input"))
                    run.etapes.append(etape)
                    self._ajouter(run.conversation, {"type": "etape", "texte": etape})
                elif type_ == "tool_result":
                    sortie = e.get("output")
                    if isinstance(sortie, str) and (sortie.startswith("BLOCKED:")
                                                    or "without a user present to approve" in sortie[:4000]):
                        approbation = True
                        etape = "action bloquée : à confirmer sur Discord ou au terminal"
                        run.etapes.append(etape)
                        self._ajouter(run.conversation, {"type": "etape", "texte": etape})
                elif type_ == "result":
                    if isinstance(e.get("text"), str):
                        final = masquer(e["text"])[:TEXTE_MANAGER_MAX]
                    if isinstance(e.get("exit_code"), int):
                        code = e["exit_code"]
                    t = e.get("tokens")
                    if isinstance(t, dict):
                        jetons = sum(v for v in t.values() if isinstance(v, int) and not isinstance(v, bool))
            proc.wait()
        finally:
            chien.cancel()
            if proc.poll() is None:
                self._tuer(proc)
        if run.delai_depasse:
            statut = "delai_depasse"
        elif run.arrete:
            statut = "arrete"
        elif code == 0 or (code is None and proc.returncode == 0):
            statut = "termine"
        else:
            statut = "echec"
        reponse = final if final is not None else run.brouillon
        try:
            if reponse:
                ev = {"type": "message", "auteur": "manager", "texte": reponse}
                if approbation:
                    ev["approbation_requise"] = True
                self._ajouter(run.conversation, ev)
            self._ajouter(run.conversation, {"type": "statut", "statut": statut})
            self._audit(run, len(texte), statut, jetons, time.monotonic() - run.debut)
        finally:
            with self._verrou:
                self._runs.pop(run.conversation, None)

    def _tuer(self, proc: subprocess.Popen) -> None:
        """TERM au groupe et à tous les descendants (même passés en setsid), KILL après le délai."""
        pids = [proc.pid] + _descendants(proc.pid)
        try:
            os.killpg(proc.pid, signal.SIGTERM)
        except OSError:
            pass
        _envoyer_signal(pids, signal.SIGTERM)
        fin = time.monotonic() + self.delai_kill
        while time.monotonic() < fin:
            if proc.poll() is not None and not any(os.path.exists(f"/proc/{p}") and not self._zombie(p)
                                                   for p in pids[1:]):
                return
            time.sleep(0.1)
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except OSError:
            pass
        _envoyer_signal(pids + _descendants(proc.pid), signal.SIGKILL)
        try:
            proc.wait(timeout=2)
        except subprocess.TimeoutExpired:
            pass

    @staticmethod
    def _zombie(pid: int) -> bool:
        try:
            with open(f"/proc/{pid}/stat", "rb") as f:
                return f.read().decode("utf-8", "replace").rsplit(")", 1)[1].split()[0] == "Z"
        except (OSError, IndexError):
            return True

    def _delai(self, run: Run) -> None:
        run.delai_depasse = True
        if run.processus is not None and run.processus.poll() is None:
            self._tuer(run.processus)

    def arreter(self, donnees: dict) -> tuple[int, dict]:
        conversation = _valider_conversation(donnees.get("conversation"))
        run = self._runs.get(conversation)
        if run is None or run.processus is None:
            raise hp.ErreurHttp(409, "Aucune réponse en cours dans cette conversation.")
        run.arrete = True
        threading.Thread(target=self._tuer, args=(run.processus,), daemon=True).start()
        return 200, {"ok": True, "conversation": conversation, "statut": "arrete"}

    def archiver(self, donnees: dict) -> tuple[int, dict]:
        conversation = _valider_conversation(donnees.get("conversation"))
        source = self._chemin(conversation)
        if not os.path.isfile(source):
            raise hp.ErreurHttp(404, "Conversation inconnue.")
        if conversation in self._runs:
            raise hp.ErreurHttp(409, "Une réponse est en cours : arrêtez-la d'abord.")
        dossier = os.path.join(self.stockage, "archive")
        os.makedirs(dossier, mode=0o700, exist_ok=True)
        os.chmod(dossier, 0o700)
        with self._verrou_conv(conversation):
            shutil.move(source, self._chemin(conversation, archive=True))
            self._compteurs.pop(conversation, None)
        return 200, {"ok": True, "conversation": conversation, "statut": "archive"}
