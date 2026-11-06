# ADR 0004 — Exposition réseau : Traefik HTTPS public, liste d'IP optionnelle

- Statut : **à valider par le propriétaire** (cto, 08/10/2026)

## Décision
- L'app n'est publiée que via le reverse proxy (Traefik de l'outil de déploiement) en HTTPS, sur `console.example.test` (port interne 8080). Le conteneur ne publie aucun port.
- Liste d'IP **désactivée par défaut** (`HERMES_OS_IP_ALLOWLIST` vide) : le propriétaire consulte depuis son mobile (IP changeante) ; la sécurité repose sur TOTP, limitations et journal. Activable par variable, sans changement de code.
- Le pont n'a aucun port réseau (socket Unix monté dans le seul conteneur hermes-os).
- `X-Robots-Tag: noindex, nofollow` et `robots.txt` `Disallow: /` ; page de connexion neutre (titre sans « Hermes ») pour limiter la reconnaissance.

## Alternative (question au propriétaire)
Accès par VPN (Tailscale/WireGuard) au lieu d'Internet public : plus sûr, mais impose un client sur chaque appareil. Recommandation : public + TOTP en v1 ; VPN si l'outil gagne des actions sensibles (chat, config).
