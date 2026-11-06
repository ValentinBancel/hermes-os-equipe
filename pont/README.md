# hermes-os-pont

Petit service hôte (Python 3 stdlib, aucune dépendance) qui donne à la console hermes-os un accès **en liste blanche** aux données de Hermes, sans monter le home Hermes dans le conteneur (ADR 0002, option 2, validée le 08/10/2026).

```
conteneur hermes-os ──HTTP signé HMAC──▶ ~/.local/state/hermes-os/run/pont.sock (0660) ──▶ hermes_os_pont.py
                                                                                    ├─ lecture seule : SQLite mode=ro, JSON, YAML, Markdown, logs
                                                                                    └─ écriture : CLI `hermes` (6 commandes, sans shell)
```

## Ce que le pont expose

Lectures (`GET`, toutes signées) :

| Route | Source | Notes |
|---|---|---|
| `/v1/sante` | schémas SQLite, `cron/jobs.json`, `gateway_state.json`, `poles.yaml`, CLI | détecte une dérive de format après une mise à jour de Hermes |
| `/v1/gateway` | `gateway_state.json` + test du PID | jamais `argv` ni `hermes_home` |
| `/v1/a-decider` | conversations de la messagerie (`--messagerie`) | approbations en attente et questions des managers ; vide si la messagerie est coupée |
| `/v1/cron`, `/v1/cron/{id}`, `/v1/cron/{id}/sorties/{fichier}` | `cron/jobs.json`, `cron/executions.db`, `cron/output/` | jamais le prompt ni l'identifiant du salon de livraison |
| `/v1/consommation?jours=N`, `/v1/consommation/sessions?limite=N` | `state.db` (profil principal + `profiles/*/state.db`) | tokens et métadonnées de session seulement : ni messages, ni prompt système |
| `/v1/poles` | `studio/organisation/poles.yaml` + `profiles/*/config.yaml` | de `config.yaml` : `model.default` et `skills.disabled`, **rien d'autre** |
| `/v1/erreurs?heures=N` | `logs/errors.log` (+ profils) | lignes WARNING/ERROR/CRITICAL, messages masqués |
| `/v1/vault` | `vault/` et `profiles/*/vault/` (via `agent.vault_store` de Hermes) | métadonnées seulement (libellé, type, site, identifiant, 2FA oui/non) : **jamais** de mot de passe, clé 2FA, carte ni adresse |

Formes exactes des réponses : `pont/contrat.json` (vérifié côté pont et côté Laravel).

Actions (`POST /v1/actions/{action}`, corps JSON) — syntaxe CLI vérifiée avec `--help` le 08/10/2026 (Hermes 0.21.5) :

| Action | Arguments | Commande exécutée |
|---|---|---|
| `cron.run` / `cron.pause` / `cron.resume` | `job_id` (`^[a-f0-9]{12}$`, existant) | `hermes cron run\|pause\|resume ID` |
| `vault.create` | `profil`, `kind` (login/payment/address), `label`, `origin` (schéma + hôte), `identifier_type`+`identifier` (login), `secret` {champs du type} | `hermes_os_coffre_agent.py` → `VaultStore.add_item` |
| `vault.update` | `profil`, `handle`, champs à changer ; secret vide = valeur gardée ; `retirer_otp`?, `vider`? | crée le nouvel élément **puis** supprime l'ancien (Hermes ne sait pas modifier) : nouvelle poignée |
| `vault.delete` | `profil`, `handle` (existant dans ce coffre) | `VaultStore.remove_item` |

Coffre : la CLI `hermes vault add` est interactive (getpass), donc le pont lance `pont/hermes_os_coffre_agent.py` avec l'interpréteur de Hermes (`hermes --print-runtime-command`, ou `--hermes-python`/`--hermes-agent`), requête JSON **sur stdin** (jamais en argv), réponse sans aucune valeur secrète ; stderr n'est jamais relayé. `--sans-coffre` désactive route et actions.

`subprocess.run([...], shell=False, timeout=30)`, environnement minimal (`PATH`, `HOME`, `LANG`, `HERMES_HOME`), stdin vide ou corps de carte. `--` avant les arguments libres : un texte commençant par `-` reste du texte. Aucune écriture directe dans un fichier ou une base de Hermes.

## Sécurité

- **Socket Unix seulement**, `0660`, aucun port TCP. Le dossier `~/.local/state/hermes-os/run` est le seul montage du conteneur.
- **Signature** : `X-Pont-Horodatage` (secondes Unix) et `X-Pont-Signature` = `hex(HMAC-SHA256(secret, "horodatage\nMÉTHODE\nchemin?requête\ncorps"))`. Fenêtre ±30 s, comparaison à temps constant, rejeu d'une même signature refusé pour les actions.
- **Chemins** : segments validés par expression régulière, `realpath` puis confinement au home Hermes (`--hermes-home`, défaut `~/.hermes`) ; tout chemin qui traverse `.env*`, `auth.json`, `auth.lock`, `vault/`, `pairing/`, `.config/`, `.ssh/`, `.git/` est refusé, y compris via un lien symbolique.
- **SQLite** en `file:…?mode=ro` + `PRAGMA query_only`, colonnes nommées (jamais `*`, vérifié par un test).
- **Masqueur** sur tout texte libre : clés `sk-…`, `ghp_…`/`github_pat_…`, `xox…`, `AKIA…`, `AIza…`, JWT, jetons Discord, webhooks, `Bearer/Basic/Token …`, `password=`/`token=`/`secret=`/`api_key=`…, identifiants dans les URL, clés PEM.
- Journal du pont : méthode, chemin (sans requête) et statut uniquement ; jamais d'en-tête, de corps ni de secret.

## Installation (hôte, utilisateur qui fait tourner Hermes)

```bash
# 1. Code (copie du dépôt, pas de lien vers un clone de travail)
install -d -m 0750 ~/hermes-os-pont ~/.local/state/hermes-os/run
install -m 0640 pont/hermes_os_pont.py ~/hermes-os-pont/

# 2. Secret partagé (≥ 32 caractères), 0600, hors dépôt ; le même dans l'environnement de la console (HERMES_PONT_SECRET)
install -d -m 0700 ~/.config/hermes-os-pont
( umask 077; python3 -c 'import secrets; print(secrets.token_hex(32))' > ~/.config/hermes-os-pont/secret )

# 3. Service utilisateur
install -D -m 0644 pont/hermes-os-pont.service ~/.config/systemd/user/hermes-os-pont.service
systemctl --user daemon-reload
systemctl --user enable --now hermes-os-pont
systemctl --user status hermes-os-pont
```

`loginctl enable-linger <utilisateur>` doit être actif pour que l'unit `--user` survive à la déconnexion.

Côté console (conteneur) : bind mount `~/.local/state/hermes-os/run` → `/run/hermes-os`, variables `HERMES_PONT_DRIVER=pont`, `HERMES_PONT_SOCKET=/run/hermes-os/pont.sock`, `HERMES_PONT_SECRET=<contenu du fichier secret>`. Le conteneur tourne en uid 1000 : il peut ouvrir le socket `0660` si l'utilisateur hôte a l'uid/gid 1000 (sinon, ajuster `--user` du conteneur).

Vérification après installation (lecture seule) : la page d'accueil de la console affiche l'état « Données à jour » ; en cas de problème, `journalctl --user -u hermes-os-pont -n 50`.

Rotation du secret : régénérer le fichier, mettre à jour `HERMES_PONT_SECRET` côté console, `systemctl --user restart hermes-os-pont`, redéployer l'application.

## Mode distant (L4) : relier un Hermes à une console distante

Le pont sort en HTTPS vers la console ; rien à ouvrir côté Hermes, aucun socket à monter. Le plus simple : dans la console, **Compte → Hermes relié → Générer un code**, puis lancer sur la machine du Hermes la commande affichée (installeur servi par la console : `/pont/installer.sh`). À la main, mêmes étapes :

```bash
python3 hermes_os_pont.py relier https://console.exemple.fr --code ABCD-EFGH   # identité 0600 + service systemd --user
python3 hermes_os_pont.py etat                                                 # console, instance, service
python3 hermes_os_pont.py delier                                               # oublie l'identité, arrête le service
```

`distant` (lancé par le service) pousse les relevés toutes les 15 s (santé, cron et détail de chaque tâche, consommation, pôles, erreurs…) et exécute les actions reçues par long-poll, avec les mêmes validations que le mode socket. Il s'arrête de lui-même si la console répond 401 (Hermes délié). HTTP n'est accepté que vers `localhost`/`127.0.0.1` (tests) ; sinon HTTPS obligatoire.

- **Identité** : `$HERMES_HOME/hermes-os-pont/instance.json` (`0600`, dossier `0700` ; `HERMES_HOME` = `--hermes-home`, `~/.hermes` par défaut). Elle contient l'URL de la console, l'`instance_id` et le secret ; `delier` la supprime. Sans elle, `distant` et `etat` répondent « Ce Hermes n'est relié à aucune console : <chemin> est absent » : si le home Hermes est ailleurs, précisez `--hermes-home`.
- **Service** : `~/.config/systemd/user/hermes-os-pont.service`. Si une unité de ce nom est **déjà active**, `relier` s'arrête avant l'appairage (le code n'est pas consommé, rien n'est modifié) : soit `systemctl --user disable --now hermes-os-pont.service` puis relancer `relier`, soit `relier … --sans-systemd` et lancer `distant` à la main.

## Prérequis de l'hôte

Aucune dépendance : la garde d'approbations de la messagerie lit `config.yaml` avec un lecteur strict de la bibliothèque standard (clés `approvals`, `hooks`, `hooks_auto_accept` seulement) ; toute construction qu'il ne comprend pas à cet endroit (ancre, alias, flux, fusion `<<`, doublon…) refuse l'envoi (échec fermé, 409).

## Tests

```bash
python3 -m unittest discover -s pont/tests -v      # 91 tests, ~20 s, aucun accès au vrai home Hermes
```

- `tests/fixtures.py` : faux home Hermes (mêmes schémas que Hermes 0.21.5), secrets « appâts » dans `.env`, `auth.json`, `vault/`, `pairing/`, liens symboliques piégés, faux binaire `hermes`.
- `tests/test_pont.py` : masqueur, signature (fenêtre, rejeu), chaque route, « aucune route vers un secret » (`../`, `%2e%2e`, double encodage, liens symboliques, octet nul…), actions (argv exact, validation, délai, échec CLI), socket `0660`.
- `tests/test_contrat.py` : réponses conformes à `contrat.json`.
- Côté Laravel, `tests/Feature/Hermes/PontIntegrationTest.php` lance ce pont sur la même fixture et le lit avec `PontClient` (curl sur socket Unix).

Lancement manuel ponctuel sur les vraies données, en lecture et sans écriture possible (faux binaire `hermes`) :

```bash
python3 pont/hermes_os_pont.py --socket /tmp/pont-essai/pont.sock --secret-file <fichier 0600> --hermes-bin /bin/false -v
```

## Messagerie avec les managers (ADR 0008)

Option `--messagerie` (désactivée par défaut) : routes `GET /v1/messagerie`, `GET /v1/messagerie/<c_…>` et actions
signées `messagerie.envoyer|arreter|archiver`. Le pont lance `hermes -p <profil> chat --query-file - --format
stream-json --source hermes-os` (liste d'arguments, texte sur stdin, environnement en liste blanche), refuse tout
profil dont les approbations sont désactivées, masque la sortie et la stocke en JSONL (0600) dans
`--messagerie-dossier`. Le module `hermes_os_messagerie.py` se déploie à côté de `hermes_os_pont.py`.
Les limites du service (3 Go, 512 tâches) tiennent compte des processus hermes lancés.
