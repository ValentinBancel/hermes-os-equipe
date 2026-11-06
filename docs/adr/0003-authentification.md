# ADR 0003 — Authentification : compte unique, Fortify + TOTP obligatoire

- Statut : **à valider par le propriétaire** (cto, 08/10/2026)

## Décision
- **Fortify** v1.41.0 (sans vues ; écrans sur mesure) pour la connexion, le TOTP (google2fa), les codes de récupération et la limitation des tentatives. Inscription, réinitialisation du mot de passe par e-mail et vérification d'e-mail **désactivées**.
- Compte créé uniquement en ligne de commande : `php artisan hermes-os:compte`. L'enrôlement TOTP est **forcé à la première connexion** ; aucune page n'est servie tant que le TOTP n'est pas confirmé.
- Mot de passe ≥ 16 caractères, `Password::uncompromised()`.
- Limites : 5/min sur la connexion et sur le code TOTP ; verrouillage 15 min après 10 échecs ; reconfirmation TOTP pour toute action d'écriture si la dernière date de plus de 15 min.
- Sessions : stockées en base, 30 min d'inactivité, 12 h maximum, cookies `Secure`, `HttpOnly`, `SameSite=Strict`.
- Perte du téléphone : codes de récupération, puis commande `php artisan hermes-os:reinitialiser-2fa` (accès serveur requis).

## Alternatives écartées
- Passkeys/WebAuthn seuls : plus résistants à l'hameçonnage, mais le brief demande TOTP ; ajoutables en v2.
- SSO/OAuth (GitHub, Google) : dépendance externe pour un seul utilisateur ; YAGNI.
- Basic auth Traefik devant l'app : ni 2FA ni journal ; possible en défense supplémentaire si le propriétaire le souhaite.

## Amendement du 24/10/2026 : appareils de confiance (demande du propriétaire)
- Le code TOTP est sauté **à la connexion** seulement si les deux conditions tiennent : cookie d'appareil valide (30 jours, chiffré, `HttpOnly`, `SameSite=Strict`, seule l'empreinte SHA-256 du jeton en base) **et** IP dans un réseau de confiance déclaré depuis la page Compte (IPv4 /32, IPv6 /64). Le mot de passe reste exigé.
- Création : case « Faire confiance à ce PC » de l'écran de code, ou bouton de la page Compte (sous code), proposées uniquement depuis un réseau de confiance.
- Session ouverte sans code : revérifiée à chaque requête (appareil révoqué ou expiré, IP hors réseau → déconnexion), 8 h d'inactivité et 7 jours maximum. Les actions d'écriture redemandent toujours le code (règle des 15 min inchangée).
- Révocation : par appareil ou en bloc depuis Compte ; automatique au changement de mot de passe et à `hermes-os:reinitialiser-2fa`. Tout est journalisé (`appareil.*`, `confiance.*`, `session.appareil_invalide`).
