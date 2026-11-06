# ADR 0009 — Pont distant : Hermes relié à la console par une connexion sortante HTTPS signée

- Statut : **appliquée** (lots 04 et 05 de l'équipe, novembre 2026). Complète l'ADR 0002 (pont en liste blanche) sans la remplacer : le pilote `socket` reste le chemin « même machine ».
- Implémentation : `pont/hermes_os_distant.py`, `pont/hermes_os_pont.py` (commandes `relier`, `distant`, `delier`, `etat`), `app/Pont/Appairage.php`, `app/Http/Middleware/VerifierSignaturePont.php`, `app/Http/Controllers/Pont/`, `app/Hermes/DistantClient.php`, `resources/pont/installer.sh`.

## Contexte
- L'ADR 0002 suppose que la console et Hermes tournent sur la même machine : la console lit un socket Unix monté depuis l'hôte.
- La console doit pouvoir superviser un Hermes installé ailleurs (poste personnel, autre serveur), souvent derrière un NAT ou un pare-feu, sans IP fixe.
- Ouvrir un port sur la machine de Hermes, ou y exposer l'`api_server`, élargirait la surface d'attaque, ce que l'ADR 0002 refuse justement.
- Le dépôt est privé : on ne peut pas demander à la machine de Hermes de faire un `git clone` pour installer le pont.

## Décision
- **Sens de connexion** : c'est le pont qui appelle la console, en HTTPS sortant. Aucun port n'est ouvert côté Hermes. Le pont pousse ses relevés toutes les 15 s (`POST /pont/v1/releves`) et récupère les actions par long-poll (`GET /pont/v1/actions`, 25 s maximum).
- **HTTPS obligatoire** : le pont refuse une console en HTTP, sauf en bouclage (`localhost`, `127.0.0.1`, `::1`) pour les essais locaux. Il ne suit aucune redirection.
- **Signature de chaque requête** : `X-Hermes-Signature = hex(HMAC-SHA256(secret, MÉTHODE\nchemin?requête\nhorodatage\nnonce\nhex(sha256(corps))))`.
  - Horodatage accepté à **±300 s** de l'horloge de la console.
  - Nonce de 128 bits à usage unique, mémorisé **10 min** (table `pont_nonces`, unicité `(instance, nonce)`) : un rejeu est refusé.
  - Le secret est chiffré en base avec `APP_KEY`.
- **Appairage par code** : la personne génère un code dans Compte (TOTP demandé).
  - Code de **8 caractères** (`XXXX-XXXX`), valable **10 min**, **usage unique**, invalidé après **5 essais faux**.
  - L'échange du code crée l'identité de l'instance (`instance_id` + secret), écrite par le pont dans `$HERMES_HOME/hermes-os-pont/instance.json` (`0600`, dossier `0700`).
- **Une seule instance reliée en v1** : en relier une autre révoque la précédente. **Délier** (Compte) révoque l'accès : le pont reçoit 401 et s'arrête. `delier` côté pont supprime le fichier d'identité et le service.
- **Actions** : file `pont_actions`. Une action non récupérée ou sans résultat **expire après 90 s**. Le pont ignore une action vieille de plus de 90 s, et la console n'accepte un résultat que de l'instance qui a reçu l'action.
- **Installeur servi par la console** : `GET /pont/installer.sh` puis `GET /pont/fichiers/<module>`. La commande `curl -fsSL https://<console>/pont/installer.sh | sh` est affichée dans Compte. L'installeur vérifie python3 ≥ 3.11 (stdlib seulement), télécharge les modules du pont, lance `relier` puis installe le service `systemd --user` `hermes-os-pont.service`.
- **Service déjà présent** : si `hermes-os-pont.service` est déjà active, `relier` s'arrête **avant l'appairage**. Le code n'est pas consommé et rien n'est modifié. Le message explique comment remplacer l'unité (`systemctl --user disable --now hermes-os-pont.service`) ou la garder (`--sans-systemd`).

## Conséquences
- La machine de Hermes n'a rien à ouvrir : un simple accès sortant en HTTPS suffit. La console n'a besoin que d'une `APP_URL` en HTTPS et de `HERMES_PONT_DRIVER=distant`.
- Les données sont au mieux vieilles de 15 s. Une donnée de plus de 60 s est affichée « périmée ».
- L'horloge compte : un décalage de plus de 5 min entre les deux machines fait refuser toutes les requêtes (401). NTP est donc nécessaire des deux côtés.
- `/pont/*` échappe à `HERMES_OS_IP_ALLOWLIST` (le pont n'a pas d'IP fixe), mais reste protégé par la signature et par des limites de débit et de taille (README, section « Relier un Hermes »).
- Une console compromise peut demander au pont les actions de la liste fermée (ADR 0002), mais rien d'autre. Le pont n'exécute aucun argument libre.
- La v1 ne supervise qu'un Hermes à la fois.

## Alternatives écartées
1. **La console appelle le pont** (port ouvert ou tunnel côté Hermes) : il faudrait une IP joignable, une redirection de port ou un VPN, et ce serait un service de plus exposé sur Internet.
2. **Exposer l'`api_server` de Hermes** : il donne l'accès complet à l'agent, outils système compris (ADR 0002).
3. **WebSocket permanent** : temps réel plus fin, mais il faudrait Reverb ou un serveur dédié. Le long-poll HTTP suffit pour 15 s de fraîcheur (ADR 0005).
4. **Jeton porteur sans signature** : un jeton intercepté ou journalisé serait rejouable. HMAC + horodatage + nonce empêchent le rejeu.
5. **Installation par `git clone` ou `pip`** : impossible sur un dépôt privé, et ajouterait des dépendances. L'installeur servi par la console livre exactement la version du pont attendue par cette console.
6. **Plusieurs instances reliées dès la v1** : il faudrait des données séparées par instance dans toute l'interface. Reporté.
