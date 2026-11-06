# hermes-os

Console web privée pour superviser et piloter une équipe d'agents Hermes : accueil (à décider, en cours), messagerie avec les managers, tâches planifiées, pôles, consommation, coffre et journal d'audit.

Le Kanban et le Terminal intégré ont été retirés (lot 01) : les décisions passent par la messagerie (approbations et questions des managers), l'activité par les sessions actives.

État (novembre 2026) : interface complète (accueil, messagerie, tâches planifiées, pôles, consommation, coffre, journal, Compte), authentification avec TOTP obligatoire, image de production, et **un Hermes relié à distance** depuis Compte (pont sortant HTTPS, [ADR 0009](docs/adr/0009-pont-distant.md)). Sans Hermes relié, la console affiche un jeu de démonstration. Cadrage et décisions : `docs/cadrage.md`, `docs/architecture.md`, `docs/adr/`.

## Installer en 10 minutes

Temps attendu : **environ 10 minutes de manipulations**, plus la **construction de l'image Docker au premier démarrage** (une seule fois par machine : 18 min mesurées à froid le 04/11/2026 sur un serveur Debian ; ensuite seul le démarrage compte, 21 s mesurées). Mesure complète à froid, de `git clone` à « En ligne » : 18 min 34 s, dont 17 min 58 s de build de l'image, 21 s de démarrage, 15 s pour le compte et la liaison (hors temps de lecture et de saisie). Trois étapes, dans cet ordre.

### 1. La console (≈ 5 min, + build de l'image au premier démarrage)

Prérequis : Docker. Pour relier un Hermes situé sur **une autre machine**, la console doit être joignable en **HTTPS** (reverse proxy devant le conteneur) ; en HTTP, seul un Hermes de la même machine (`localhost`) peut se relier.

```bash
git clone https://github.com/ValentinBancel/hermes-os-equipe.git hermes-os && cd hermes-os
cp .env.example .env
# Dans .env :
#   HERMES_PONT_DRIVER=distant              (le .env.example reste en demo : un clone neuf montre la démonstration)
#   APP_URL=https://console.exemple.fr      (l'adresse que le Hermes utilisera ; en local : http://localhost)
#   si vous changez le port (APP_PORT=8081), mettez-le aussi dans APP_URL : http://localhost:8081
docker compose up -d                        # = sail up -d ; rend la main une fois l'image construite (18 min à froid), console prête ~20 s après
./vendor/bin/sail artisan hermes-os:compte --email=vous@example.test --nom=Camille
```

- Modifiez `.env` **avant** le premier `docker compose up -d`. Si vous le modifiez ensuite : `docker compose up -d` si vous avez changé `APP_PORT`, sinon `docker compose restart` (le démarrage vide les caches de configuration).
- Image de production à la place de Sail : voir [Production (image Docker)](#production-image-docker). Variables : `APP_URL` (HTTPS), `APP_KEY` (`php artisan key:generate --show`), `HERMES_PONT_DRIVER=distant`, `HERMES_OS_CLIENT` (facultatif), `HERMES_OS_TRUSTED_PROXIES` (CIDR du reverse proxy). L'image met la configuration en cache au démarrage : après toute modification de variable, **recréez le conteneur** (redéploiement ou nouveau `docker run`). Le compte se crée par `docker exec -it <conteneur> php artisan hermes-os:compte --email=… --nom=…`.
- Connectez-vous sur `APP_URL/connexion` et enrôlez le TOTP (obligatoire).

### 2. Le Hermes, sur sa machine (≈ 2 min)

Prérequis : `python3` ≥ 3.11 (bibliothèque standard seulement), `curl`, un terminal interactif.

1. Dans la console : **Compte → Hermes relié → Générer un code** (code de 8 caractères, valable 10 min).
2. Sur la machine du Hermes, lancez la commande affichée dans Compte, puis saisissez le code :

```bash
curl -fsSL https://console.exemple.fr/pont/installer.sh | sh
# options transmises à « relier » : curl -fsSL …/pont/installer.sh | sh -s -- --sans-systemd --hermes-home ~/.hermes
```

Depuis un clone du dépôt, sans installeur : `python3 pont/hermes_os_pont.py relier https://console.exemple.fr` (même code). L'identité est écrite dans `$HERMES_HOME/hermes-os-pont/instance.json` (`~/.hermes` par défaut, `0600`), puis le service `systemd --user` `hermes-os-pont.service` démarre.

### 3. Vérifier (≈ 1 min)

- Compte affiche l'instance « En ligne » et l'accueil affiche les données de ce Hermes (premier relevé en moins de 15 s).
- Sur la machine du Hermes : `python3 ~/.local/share/hermes-os-pont/hermes_os_pont.py etat` (ou `python3 pont/hermes_os_pont.py etat` depuis un clone) doit répondre `"relie": true`.

## Dépannage

Un cas = symptôme → cause → commande.

- **Compte n'affiche pas « Générer un code »** → la console n'est pas en pilote `distant` → `HERMES_PONT_DRIVER=distant` dans `.env`, puis `docker compose restart` (Sail) ou recréer le conteneur (image de production).
- **Compte affiche l'instance « Hors ligne » ou des données « périmées »** → le pont ne tourne pas ou n'atteint pas la console → `python3 ~/.local/share/hermes-os-pont/hermes_os_pont.py etat`, puis `systemctl --user status hermes-os-pont` et `journalctl --user -u hermes-os-pont -n 50`. Vérifier que la machine du Hermes joint `APP_URL` : `curl -I https://console.exemple.fr/up`.
- **Le journal du pont dit « La console refuse ce Hermes (instance déliée ou horloge décalée) »**, alors que l'instance n'a pas été déliée → horloges décalées de plus de 5 min (signature valable ±300 s) → `timedatectl` sur les deux machines ; activer NTP : `sudo timedatectl set-ntp true`.
- **« HTTP refusé : la console doit être en HTTPS »** → `APP_URL` (ou l'URL donnée à `relier`) est en `http://` vers une autre machine → mettre la console derrière un reverse proxy HTTPS et passer `APP_URL` en `https://…` ; HTTP n'est admis qu'en local (`localhost`, `127.0.0.1`).
- **L'installeur répond 500** → `APP_URL` de la console est en HTTP vers une autre machine (ou contient des caractères refusés) → passer `APP_URL` en `https://…`, puis `docker compose restart` (Sail) ou recréer le conteneur (image de production).
- **« Ce Hermes n'est relié à aucune console : <chemin> est absent »** → pas d'identité à cet endroit : jamais relié, délié, ou home Hermes ailleurs → `relier <url>` avec un nouveau code, ou ajouter `--hermes-home <home-hermes>`.
- **Changer de console, ou repartir de zéro** → Compte → **Délier** (le pont reçoit 401 et s'arrête), puis générer un nouveau code et relancer la commande de l'installeur (ou `relier <url>`). Côté Hermes, `hermes_os_pont.py delier` supprime l'identité et le service.
- **« hermes-os-pont.service est déjà active »** → un pont du même nom tourne déjà sur cette machine. Rien n'a été modifié et le code n'a pas été consommé → pour le remplacer : `systemctl --user disable --now hermes-os-pont.service`, puis relancer ; pour le garder : relancer avec `--sans-systemd` et démarrer ce pont-ci à la main (`hermes_os_pont.py distant --hermes-home <home-hermes>`).
- **« Pas de terminal pour saisir le code d'appairage »** → installeur lancé sans terminal (cron, `ssh -T`) → relancer depuis un terminal interactif (`ssh -t`).
- **Code refusé** → code expiré (10 min), déjà utilisé ou invalidé après 5 essais faux → générer un nouveau code dans Compte.

## Développement / détails

### Démarrage (développement, Sail)

Prérequis : Docker. Rien d'autre à installer ni à configurer.

```bash
git clone https://github.com/ValentinBancel/hermes-os-equipe.git hermes-os && cd hermes-os
docker compose up -d          # = sail up -d ; premier démarrage : quelques minutes
# → http://localhost/connexion
```

Au premier démarrage, `docker/8.5/bootstrap.sh` fait tout seul : `.env` depuis `.env.example`, `composer install`, `APP_KEY`, base SQLite `database/database.sqlite`, migrations, `npm ci`, `npm run build`. Il est idempotent (relancé à chaque démarrage, il ne refait que ce qui a changé, par exemple après un `git switch`).

- Aucune base externe (SQLite), aucun montage du home Hermes : l'accès aux données Hermes passe par le pont (lot 05), ici en mode `demo` (`HERMES_PONT_DRIVER=demo`).
- Ports : `APP_PORT` (défaut 80) et `VITE_PORT` (défaut 5173), par exemple `APP_PORT=8081 docker compose up -d`.
- Rechargement à chaud des CSS/JS : `./vendor/bin/sail npm run dev`.
- Tests : `./vendor/bin/sail artisan test` · style : `./vendor/bin/sail bin pint` · pont : `python3 -m unittest discover -s pont/tests`.

### Données Hermes : pont ou démonstration

Le conteneur ne monte **rien** du home Hermes (ADR 0002). Tout passe par `App\Hermes\HermesClient`, choisi par `HERMES_PONT_DRIVER` :

- `demo` (défaut, clone neuf et CI) : `DemoClient`, jeu de données figé relevé le 08/10/2026, mêmes formes que le pont. `HERMES_DEMO_ETAT=gateway_arrete` ou `pont_injoignable` simule les états dégradés de l'accueil.
- `socket` (même machine ; `pont` = alias historique) : `PontClient`, HTTP signé HMAC-SHA256 sur le socket Unix `HERMES_PONT_SOCKET` du pont hôte, cache 4 s (borné à 3-5 s), dernières données connues rendues « périmées » si le pont ne répond plus. Installation du pont : `pont/README.md`.

- `distant` (L4, Hermes sur une autre machine) : `DistantClient`. Le pont sort en HTTPS vers la console (aucun port ouvert côté Hermes) : il pousse ses relevés toutes les 15 s (`POST /pont/v1/releves`) et récupère les actions par long-poll (`GET /pont/v1/actions`, 25 s max). Une donnée de plus de 60 s est « périmée » ; une action non récupérée expire après 90 s. Le pilote `socket` (alias historique `pont`) reste le chemin « même machine ».

#### Relier un Hermes (pilote distant)

1. **Compte → Hermes relié → Générer un code** (TOTP demandé) : code de 8 caractères, valable 10 min, usage unique, 5 essais.
2. Sur la machine du Hermes : lancer la commande affichée dans Compte (`curl -fsSL https://<console>/pont/installer.sh | sh`), puis saisir le code. L'installeur télécharge les modules du pont, échange le code contre une identité (`instance_id` + secret, 0600) et installe un service `systemd --user`.
3. La console affiche l'instance dès le premier relevé. **Délier** révoque l'accès : le pont reçoit 401 et s'arrête.

Prérequis côté console : `HERMES_PONT_DRIVER=distant` (le `.env.example` reste en `demo` pour qu'un clone neuf affiche la démonstration) et une `APP_URL` en HTTPS (HTTP accepté seulement en bouclage, pour les essais locaux). Si `APP_URL` n'est ni en HTTPS ni en bouclage, l'installeur répond 500. Sans terminal (cron, `ssh -T`), l'installeur s'arrête et demande de relancer depuis un terminal : `--code <code>` existe pour la CI, mais le code reste alors visible dans `ps` et l'historique du shell, à éviter ailleurs.

Sécurité : chaque requête du pont est signée HMAC-SHA256 (méthode, chemin, horodatage ±300 s, nonce à usage unique, corps) ; le secret est chiffré en base (`APP_KEY`) ; l'appairage est limité à 10 essais/min/IP et à 4 Kio, le reste de l'API à 600 requêtes/min/IP et 240/min/IP par instance, les corps à 2 Mo ; une action livrée n'accepte de résultat que de l'instance qui l'a reçue et avant son expiration (90 s) ; le pont ne suit aucune redirection et ignore une action plus vieille que 90 s ; `/pont/*` échappe à `HERMES_OS_IP_ALLOWLIST` (le pont n'a pas d'IP fixe) mais reste derrière la signature. Une seule instance reliée à la fois : en relier une autre révoque la précédente.

Bout en bout sans conteneur ni donnée réelle : `scripts/e2e-pont-distant.sh` (console `artisan serve` + pont Python sur `pont/tests/demo-home`), lancé en CI.

Actions d'écriture (créer/commenter/débloquer une carte, lancer/suspendre/reprendre une tâche planifiée) : uniquement via `App\Hermes\ExecuteurActions` — TOTP confirmé depuis moins de 15 min, 20 actions/min, arguments validés, ligne d'audit `pending` puis `ok`/`failed`.

### Créer le compte

Il n'existe **aucune inscription** par l'interface : un seul compte, créé en ligne de commande.

```bash
./vendor/bin/sail artisan hermes-os:compte --email=vous@example.test --nom=Camille   # ajouter --provisoire pour un mot de passe provisoire à changer à la 1re connexion
# mot de passe demandé de façon masquée : 16 caractères minimum, refusé s'il figure dans Have I Been Pwned
```

En production : `php artisan hermes-os:compte` dans un shell du conteneur (`docker exec -it <conteneur> php artisan hermes-os:compte`). Pour un script, `--mot-de-passe-stdin` lit le mot de passe sur l'entrée standard (jamais en argument, pour qu'il ne finisse pas dans l'historique).

À la première connexion, l'enrôlement TOTP est **forcé** : aucune autre page n'est servie avant la confirmation d'un code. Les 8 codes de récupération sont affichés une seule fois juste après.

Téléphone et codes de récupération perdus : `php artisan hermes-os:reinitialiser-2fa` (accès serveur requis) efface le TOTP et ferme toutes les sessions ; réenrôlement à la connexion suivante.

### Sécurité (résumé)

| Point | Réglage |
|---|---|
| Comptes | un seul, créé en CLI ; inscription, réinitialisation par e-mail, vérification d'e-mail, passkeys : désactivées (routes absentes, 404) |
| Mot de passe | ≥ 16 caractères, `Password::uncompromised()` |
| TOTP | obligatoire (Fortify, confirmé), codes de récupération à usage unique |
| Limitations | 5 / min sur la connexion (identifiant + IP) et sur le code TOTP ; verrouillage 15 min après 10 échecs (mot de passe et TOTP confondus) |
| Sessions | en base, chiffrées, 30 min d'inactivité, 12 h maximum, cookie `sid` `Secure` `HttpOnly` `SameSite=Strict` ; « se souvenir de moi » ignoré |
| Journal d'audit | table `audit_log` en ajout seul : connexion réussie/échouée, verrouillage, TOTP (demande, échec, succès, enrôlement, code de récupération), déconnexion, expiration de session, commandes CLI, actions Hermes (`action.*` : `pending` complété une seule fois en `ok`/`failed`, refus en `blocked`) |
| En-têtes | CSP avec nonce par requête (`default-src 'self'`, sans `unsafe-inline` ni `unsafe-eval`), Livewire `csp_safe`, HSTS 1 an, `nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, `X-Frame-Options: DENY`, `X-Robots-Tag: noindex, nofollow` ; `robots.txt` `Disallow: /` |
| Réseau | liste d'IP optionnelle `HERMES_OS_IP_ALLOWLIST` (CIDR, vide = désactivée ; `/up` toujours joignable) ; proxys de confiance limités au réseau Traefik `10.0.1.0/24` (`HERMES_OS_TRUSTED_PROXIES`) |

Les valeurs par défaut sûres sont dans `config/*.php` (`config/hermes-os.php`, `config/session.php`) : un `.env` ancien qui ne contient pas les nouvelles clés reste sûr.

### Production (image Docker)

`Dockerfile` multi-étapes : Node 24 (Vite) → Composer `--no-dev` → `dunglas/frankenphp:1.13-php8.5`.

- FrankenPHP (Caddy + PHP 8.5) en HTTP sur **:8080**, TLS chez le reverse proxy ; utilisateur uid 1000 ; compatible `--read-only` (seuls `/app/storage` et `/tmp` sont écrits).
- Au démarrage : migrations SQLite (`/app/storage/data/database.sqlite`) puis `php artisan optimize`.
- `HEALTHCHECK` sur `/up`.
- Variables (outil de déploiement, jamais dans le dépôt) : `APP_KEY` (obligatoire, `php artisan key:generate --show`), `APP_URL`, `HERMES_OS_CLIENT` (optionnel : affiche « pour <client> » sous le wordmark), `HERMES_OS_TRUSTED_PROXIES` (CIDR du reverse proxy, vide par défaut), `HERMES_OS_IP_ALLOWLIST` (optionnel), `HERMES_PONT_DRIVER=distant` (recommandé : rien à monter, voir [Relier un Hermes](#relier-un-hermes-pilote-distant)) ou `HERMES_PONT_DRIVER=pont` avec `HERMES_PONT_SOCKET=/run/hermes-os/pont.sock`, `HERMES_PONT_SECRET` et un bind mount du dossier du socket du pont (`~/.local/state/hermes-os/run` par défaut) → `/run/hermes-os` (socket du pont, rien d'autre). Volume `hermes-os-data` → `/app/storage`. Sans ces variables, l'image tourne en `demo`.

```bash
docker build -t hermes-os .
docker run -p 8080:8080 --read-only --tmpfs /tmp -v hermes-os-data:/app/storage \
  -e APP_KEY=base64:… hermes-os
```

### Vérification « clone neuf » (avant toute PR)

```bash
scripts/verifier-clone-neuf.sh [branche]
```

Clone la branche dans un dossier vide, lance `docker compose up -d` seul (pages, en-têtes, tests Pest sous PHP 8.5, création du compte en CLI, connexion jusqu'à l'enrôlement TOTP), puis build et lance l'image de production en lecture seule (mêmes contrôles + healthcheck). Ressources isolées `hermes-os-verif-*`, ports 18080/15173/18090 sur 127.0.0.1, nettoyage complet à la fin (`down -v --rmi local`).

Dans les deux cas, il rejoue aussi « Relier un Hermes » de bout en bout (`scripts/e2e/relier-console.sh`, également lancé par le job CI `e2e`) : pilote `distant`, connexion et enrôlement TOTP, code d'appairage obtenu dans Compte, `hermes_os_pont.py relier … --sans-systemd` puis boucle `distant` sur un home Hermes de démonstration ; Compte doit afficher l'instance « En ligne » et l'accueil les données de démonstration. Le pont tourne sur l'hôte (python3 ≥ 3.11) avec un HOME jetable : rien n'est écrit dans le vrai home. Le scénario seul contre une console déjà lancée : `printf '%s\n' "$MDP" | scripts/e2e/relier-console.sh <url> <email>`.

### Versions vérifiées (08/10/2026)

| Paquet | Version installée | Source |
|---|---|---|
| PHP | 8.5.11 (image FrankenPHP et runtime Sail 8.5) | Docker Hub, `laravel/sail` runtimes |
| laravel/laravel (squelette) | v13.11.0 | Packagist |
| laravel/framework | v13.35.0 | Packagist |
| livewire/livewire | v4.4.7 (`csp_safe`) | Packagist |
| laravel/fortify | v1.41.0 (google2fa 9.1.0, bacon-qr-code 3.1.1) | Packagist |
| laravel/sail | v1.68.0 | Packagist |
| pestphp/pest | v5.3.1 (PHPUnit 13.4.1), pest-plugin-laravel v5.0.1 | Packagist |
| vite / tailwindcss / @tailwindcss/vite / laravel-vite-plugin | 8.3.4 / 4.3.3 / 4.3.3 / 3.2.0 | npm |
| FrankenPHP | v1.13.0, image `dunglas/frankenphp:1.13-php8.5` | Docker Hub |
| Node (build des assets) | 24 (`node:24-alpine`) | Docker Hub |

`spatie/laravel-csp` (prévu dans l'architecture) n'est pas installé : un middleware de ~40 lignes (`app/Http/Middleware/EnTetesSecurite.php`) suffit, avec le nonce natif de Vite (`Vite::useCspNonce()`) que Livewire réutilise.

## Licence

MIT, voir [LICENSE](LICENSE).
