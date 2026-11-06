# Cadrage — hermes-os v1

> Rédigé par le cto le 08/10/2026. Source de vérité de la mission : le brief de mission (hors dépôt).
>
> **Mise à jour du lot 01 (03/11/2026)** : document historique. Le Kanban (§ Pages, 3) et le Terminal ont été retirés ; « À décider » vient de la messagerie, « En cours » des sessions actives. Voir `docs/architecture.md`.

## Objectif
Une console web privée pour **voir et piloter** Hermes et ses 21 profils d'agents depuis n'importe où, en remplacement de `hermes dashboard` (qui n'écoute que sur `127.0.0.1:9119` et lit/écrit le `.env` : inexposable).

Utilisateur unique : le propriétaire. Usage type : un coup d'œil de 30 secondes depuis le téléphone ou le portable (« est-ce que tout tourne ? qu'est-ce qui attend ma décision ? combien ça coûte ? »), et quelques actions ponctuelles.

## Ce qui existe réellement (relevé du 08/10/2026, en lecture seule)
| Source | Format réel | Volume constaté |
|---|---|---|
| `gateway_state.json` | JSON : `gateway_state`, `platforms`, `served_profiles`, `active_agents`, `active_work`, `code_version` | gateway `running`, Discord `connected`, 21 profils servis |
| `state.db` + `profiles/*/state.db` | SQLite WAL, tables `sessions` (tokens, `estimated_cost_usd`, `profile_name`, `source`), `session_model_usage`, `messages` | 54,5 Mo pour le profil principal ; 20 profils ont leur propre base |
| `kanban/boards/<slug>/kanban.db` | SQLite : `tasks`, `task_links`, `task_runs`, `task_events`, `task_comments` | 5 tableaux : `dev`, `business`, `data`, `veille`, `vb-workflows-ia` |
| `cron/jobs.json` + `cron/output/<id>/` + `cron/executions.db` | JSON (12 tâches) + sorties texte horodatées | 1 tâche en `delivery_failed` (rapport SEO) |
| `studio/organisation/poles.yaml`, `profiles/*/config.yaml` | YAML | 4 pôles + transverse |
| `studio/missions/*` | Markdown | 3 missions |
| `logs/errors.log`, `logs/gateway.log` | texte | — |

Constat important : **les coûts sont à 0 dans `estimated_cost_usd`** pour le profil principal (abonnement, pas de facturation à l'appel). L'écran « coûts » affichera donc d'abord les **tokens** ; le coût en euros sera un coût estimé à partir d'une grille de prix configurable, clairement marqué « estimation ».

## Périmètre v1
1. **Accueil** : état du gateway et des plateformes ; agents en cours (`active_work` + cartes `running`) ; 5 prochaines tâches planifiées ; erreurs des dernières 24 h (cron en échec, `errors.log`, cartes `blocked`) ; **« ce qui attend le propriétaire »** (cartes bloquées `needs_input`, synthèses de lead).
2. **Pôles** : organigramme lead / exécutants / auditeurs / support, avec modèle et skills actifs par profil (issus de `poles.yaml` et `profiles/*/config.yaml`, sans aucun autre champ de config).
3. **Kanban** : les tableaux (dev, business, data, veille + tout tableau existant), colonnes par statut, fiche carte (corps, parents/enfants, commentaires, runs, résumé de handoff). Rafraîchissement automatique toutes les 5 s.
4. **Missions** : liste, puis pour une mission : brief, lots, audits par version, décisions, rendus en Markdown.
5. **Tâches planifiées** : liste, prochaine exécution, historique, dernière sortie ; actions **lancer maintenant**, **pause**, **reprise**.
6. **Sessions et consommation** : tokens par profil et par jour (30 jours), par modèle ; coût estimé.
7. **Actions d'écriture v1** (liste fermée) : créer une carte, commenter une carte, débloquer une carte, lancer / mettre en pause / reprendre une tâche planifiée. Toutes passent par l'agent pont et la CLI `hermes` (ADR 0002), toutes sont journalisées.
8. **Sécurité** : compte unique, TOTP obligatoire, limitation des tentatives, sessions courtes, journal d'audit consultable, en-têtes + CSP stricte, liste d'IP optionnelle (ADR 0003, 0004).

## Hors périmètre v1 (refusé ou reporté, avec la raison)
| Élément | Raison |
|---|---|
| Chat avec un agent depuis la console | Donne l'équivalent d'un terminal sur le serveur : risque maximal pour un gain faible (Discord fait déjà le travail). v2 éventuelle, après revue sécurité. |
| Édition de config, `.env`, skills, profils, modèles | Les secrets vivent au même endroit ; toute modif de config doit passer par le propriétaire. |
| Lecture intégrale des transcriptions de sessions | Les messages contiennent des sorties d'outils non filtrées (risque de fuite de secrets). v1 : métadonnées seulement (titre, profil, durée, tokens). |
| Supprimer / archiver des cartes, créer des tableaux, réassigner | YAGNI ; faisable par Discord/CLI. |
| Temps réel par WebSocket (Reverb) | Un rafraîchissement de 5 s suffit pour un utilisateur unique (ADR 0005). |
| Multi-utilisateur, rôles | Un seul compte, décision du propriétaire. |
| Gestion de l'outil de déploiement ou des conteneurs | Hors sujet ; risque sur la prod. |
| Notifications push | Discord existe déjà. |

## Définition du « fini » v1
- Clone neuf : `git clone` + `./vendor/bin/sail up` (après l'unique `composer install` documenté via conteneur) → toutes les pages répondent, avec un **jeu de données de démonstration** (pas besoin de Hermes en local).
- `docker build` du `Dockerfile` puis `docker run` : healthcheck vert, pages 200 (projet compose `hermes-os-*`, ports non standards, `down -v --rmi local`).
- Audits `design-critic`, `a11y`, `qa` sans point bloquant (`seo` non pertinent : application privée, `noindex` partout).
- Aucun secret dans l'interface (test automatisé sur des motifs de clés) ; CSP sans `unsafe-eval` ni `unsafe-inline` pour les scripts.
- PR vers `develop` ; déploiement uniquement après feu vert du propriétaire.
