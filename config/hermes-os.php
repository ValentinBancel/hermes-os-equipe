<?php

/*
 * Réglages propres à hermes-os. Les valeurs par défaut sont neutres et sûres : un .env
 * ancien sans ces clés reste fonctionnel. Voir docs/architecture.md et ADR 0003 / 0004.
 */
return [

    // Nom du client affiché « pour <client> » sous le wordmark (vide = rien).
    'client' => (string) env('HERMES_OS_CLIENT', ''),

    // Fuseau d'affichage de l'interface (l'application reste en UTC).
    'fuseau' => env('HERMES_OS_FUSEAU', 'Europe/Paris'),

    // Liste d'IP autorisées (CIDR séparés par des virgules). Vide = désactivée (ADR 0004).
    'ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HERMES_OS_IP_ALLOWLIST', ''))
    ))),

    // Proxys de confiance (CIDR séparés par des virgules) : mettre celui du reverse proxy. Vide = aucun.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('HERMES_OS_TRUSTED_PROXIES', ''))
    ))),

    'auth' => [
        // Tentatives par minute sur la connexion et sur le code TOTP.
        'tentatives_par_minute' => 5,
        // Verrouillage après N échecs, pour M minutes.
        'echecs_avant_verrouillage' => 10,
        'minutes_verrouillage' => 15,
        // Sessions : inactivité (aussi SESSION_LIFETIME) et durée maximale absolue. Contrôlées par SessionLimitee ;
        // session.lifetime (cookie et base) couvre la plus longue des deux inactivités (appareil de confiance).
        'minutes_inactivite' => (int) env('SESSION_LIFETIME', 30),
        'heures_session_max' => 12,
        // Appareil de confiance (ADR 0003, amendement) : code TOTP sauté à la connexion si cookie d'appareil valide ET
        // IP dans un réseau de confiance du compte (page Compte). Les actions redemandent toujours le code.
        'appareil' => [
            'jours_confiance' => 30,
            'heures_inactivite' => 8,
            'jours_session_max' => 7,
        ],
        // Reconfirmation TOTP exigée pour les actions d'écriture au-delà de ce délai (lot pages).
        'minutes_validite_totp' => 15,
        'mot_de_passe_min' => 16,
    ],

    // Interrupteur de la page Messagerie : défaut public ici, pas seulement dans .env.example.
    'messagerie' => (bool) env('HERMES_OS_MESSAGERIE', true),

    // Accès aux données Hermes (lot 05, ADR 0002) : `demo` = jeu figé, aucun accès au home Hermes ;
    // `pont` = socket Unix du pont hôte (pont/README.md), requêtes signées par HERMES_PONT_SECRET.
    'pont' => [
        // demo | socket (même machine, alias historique : pont) | distant (pont relié par HTTPS sortant, L4).
        'driver' => env('HERMES_PONT_DRIVER', 'demo'),
        'socket' => env('HERMES_PONT_SOCKET', '/run/hermes-os/pont.sock'),
        'secret' => env('HERMES_PONT_SECRET'),
        // Cache des lectures (borné à 3-5 s) : le wire:poll de 5 s ne martèle pas les SQLite de Hermes.
        'cache_secondes' => (int) env('HERMES_PONT_CACHE_SECONDES', 4),
        // Démonstration : `nominal`, `tout_tourne`, `gateway_arrete` ou `pont_injoignable` (états de l'accueil).
        'demo_etat' => env('HERMES_DEMO_ETAT', 'nominal'),
        // Mode distant (L4, R9) : relevé plus vieux que ce seuil → « pont injoignable ».
        'releve_perime_secondes' => (int) env('HERMES_PONT_RELEVE_PERIME', 60),
        // Durée de vie d'une action en file (R9) et attente synchrone avant de rendre la main à wire:poll.
        'action_expiration_secondes' => (int) env('HERMES_PONT_ACTION_EXPIRATION', 90),
        'attente_action_secondes' => (int) env('HERMES_PONT_ATTENTE_ACTION', 8),
        // Long-poll de GET /pont/v1/actions (R10, ≤ 25 s) et validité d'un code d'appairage (R7).
        'long_poll_max_secondes' => (int) env('HERMES_PONT_LONG_POLL', 25),
        'code_minutes' => 10,
    ],

];
