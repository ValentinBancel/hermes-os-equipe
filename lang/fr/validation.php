<?php

/*
 * Messages de validation en français (V5-7, lot 11 ; a11y v5 M1 : 3.1.2). Écrit à la main, aucun paquet : seules
 * les règles réellement employées par l'application (contrôleurs d'authentification, Fortify, commande de création
 * de compte, actions envoyées au pont) sont traduites. Ton impersonnel, comme lang/fr/auth.php.
 * Une règle ajoutée à l'application sans message ici retombe sur l'anglais (fallback_locale) : l'ajouter ici aussi.
 */
return [
    'between' => [
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit valoir oui ou non.',
    'confirmed' => 'La confirmation du :attribute ne correspond pas.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'not_regex' => 'Le format du champ :attribute est invalide.',
    'password' => [
        'letters' => 'Le :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute figure dans une fuite de données connue : en choisir un autre.',
    ],
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être un texte.',

    'attributes' => [
        'assignee' => 'profil assigné',
        'carte' => 'carte',
        'code' => 'code',
        'corps' => 'description',
        'email' => 'identifiant',
        'job_id' => 'tâche planifiée',
        'name' => 'nom',
        'password' => 'nouveau mot de passe',
        'password_confirmation' => 'confirmation',
        'priorite' => 'priorité',
        'recovery_code' => 'code de secours',
        'tableau' => 'tableau',
        'texte' => 'texte',
        'titre' => 'titre',
        'triage' => 'à qualifier',
    ],
];
