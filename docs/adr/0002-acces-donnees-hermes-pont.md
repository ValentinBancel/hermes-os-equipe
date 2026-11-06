# ADR 0002 — Accès aux données de Hermes : un « pont » hôte en liste blanche, écritures via la CLI officielle

- Statut : **validée (08/10/2026, Hermes par délégation du propriétaire)** — option 2 retenue (proposée par cto le 08/10/2026). Implémentation : `pont/` (lot 05).

## Contexte
Le brief exige : données en lecture seule, jamais de secrets, écritures par une API de Hermes et jamais par écriture directe dans ses fichiers, API jamais exposée publiquement. État réel relevé le 08/10 :
- **`api_server` de Hermes** (désactivé aujourd'hui) : expose chat, runs, sessions et **jobs cron** (`/api/jobs/{id}/run|pause|resume`), mais **pas le kanban**. Sa doc précise qu'il « donne l'accès complet au jeu d'outils de l'agent, **y compris les commandes terminal** ». Il écoute sur 127.0.0.1 par défaut : le joindre depuis un conteneur Swarm imposerait de le lier à une IP de pont Docker, donc de modifier la config et de redémarrer le gateway.
- **`hermes dashboard`** (127.0.0.1:9119) : a une API kanban (plugin) et cron, mais lit et écrit le `.env` ; inexposable.
- **CLI `hermes`** : `hermes kanban create|comment|unblock…` et `hermes cron run|pause|resume` existent ; c'est l'interface officielle, qui passe par la même logique que le gateway (verrous SQLite, dispatcher).
- Les fichiers sources sont en `0600` (`state.db`, `cron/jobs.json`) : un conteneur ne peut les lire qu'en uid 1000 avec un montage de `<HERMES_HOME>` (home de Hermes, `~/.hermes` par défaut), qui contient aussi `.env`, `auth.json`, `vault/`.

## Options
1. Monter `<HERMES_HOME>` en lecture seule dans le conteneur + `api_server` pour les écritures.
2. **Pont hôte minimal** (socket Unix) : lectures filtrées en liste blanche, écritures = CLI `hermes` en liste blanche.
3. Passer par `hermes dashboard` derrière un proxy authentifié.

## Décision : option 2
- Le conteneur web ne monte **rien** de `<HERMES_HOME>` : un bug ou une faille Laravel ne peut lire aucun secret, alors qu'avec l'option 1 un simple path traversal suffit à lire `.env`.
- Aucune modification de la config de Hermes, aucun redémarrage du gateway, aucun port ouvert.
- Les écritures restent « par une API de Hermes » au sens du brief : la CLI officielle, jamais d'`UPDATE` SQL ni d'écriture de fichier par hermes-os.
- L'option 1 donnerait à hermes-os un jeton capable de lancer un agent avec terminal : surface d'attaque disproportionnée pour 6 actions.
- L'option 3 réexpose le `.env` en cas de faille du proxy.

## Conséquences
- Un composant de plus à maintenir (`pont/`, environ 300 lignes Python stdlib, testé, unit `systemd --user`). Hermes, gestionnaire du projet, l'installe ; aucune dépendance pip.
- Couplage au format des tables SQLite et des fichiers de Hermes : le pont l'isole (un seul endroit à adapter après une mise à jour de Hermes) et expose `GET /v1/sante`, qui vérifie les schémas attendus et signale toute dérive sur l'accueil.
- Pour le chat (v2 éventuelle), on réévaluera `api_server` sur un réseau interne dédié, avec l'accord du propriétaire.
