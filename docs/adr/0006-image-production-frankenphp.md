# ADR 0006 — Image de production : FrankenPHP, image unique

- Statut : proposé (cto, 08/10/2026)

## Décision
`Dockerfile` multi-étapes : Node (build Vite) → Composer (`--no-dev`) → image finale `dunglas/frankenphp:1.13-php8.5` (FrankenPHP v1.13.0, publié le 04/10/2026). Un seul processus (Caddy + PHP), utilisateur uid 1000 (nécessaire pour le socket du pont), `HEALTHCHECK` sur `/up`, `php artisan optimize` et migrations SQLite au démarrage.

## Raisons
- Une image, un processus : pas de nginx + php-fpm + supervisord à orchestrer.
- TLS reste chez Traefik ; Caddy sert en HTTP interne.
- Mode classique (sans worker/Octane) en v1 : plus simple, suffisant pour un utilisateur.

## Conséquence
Sail (dev) utilise son image standard PHP 8.5 ; la prod, FrankenPHP. `scripts/verifier-clone-neuf.sh` teste les deux chemins.
