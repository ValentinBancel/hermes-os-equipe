# ADR 0007 — Base de l'application : SQLite

- Statut : proposé (cto, 08/10/2026)

## Décision
SQLite (fichier sur le volume `hermes-os-data`) pour le compte, les sessions, le journal d'audit, le cache et les limitations.

## Raisons
Un utilisateur, quelques milliers de lignes d'audit par an. Pas de conteneur MySQL/Postgres à faire tourner, sauvegarder et surveiller ; `sail up` démarre sans service de base.

## Réévaluer si
Plusieurs utilisateurs ou plusieurs réplicas (non prévu).
