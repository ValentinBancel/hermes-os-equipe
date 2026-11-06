<?php

namespace App\Hermes;

/**
 * Liste fermée des actions d'écriture v1 (docs/architecture.md, ADR 0002).
 * Le pont refuse toute autre action ; chaque valeur correspond à une commande `hermes` précise.
 */
enum Action: string
{
    case CronLancer = 'cron.run';
    case CronSuspendre = 'cron.pause';
    case CronReprendre = 'cron.resume';
    case MessagerieEnvoyer = 'messagerie.envoyer';
    case MessagerieArreter = 'messagerie.arreter';
    case MessagerieArchiver = 'messagerie.archiver';
    case VaultCreer = 'vault.create';
    case VaultModifier = 'vault.update';
    case VaultSupprimer = 'vault.delete';

    /** Origine d'un élément du coffre : schéma et hôte seulement (le pont revalide). */
    private const ORIGINE = '/^https?:\/\/[^\s\/?#@]+(:\d{1,5})?\/?$/';

    /** Champs secrets admis (union des trois types ; le pont filtre par type). */
    public const CHAMPS_SECRETS = [
        'password', 'otp_secret',
        'card_number', 'cardholder_name', 'exp_month', 'exp_year', 'cvc', 'billing_postal_code',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
    ];

    /**
     * @return array<string, list<string>>
     */
    private static function reglesSecret(): array
    {
        return [
            'secret' => ['nullable', 'array:'.implode(',', self::CHAMPS_SECRETS)],
            'secret.*' => ['nullable', 'string', 'max:1024', 'not_regex:/\x00/'],
        ];
    }

    public function libelle(): string
    {
        return match ($this) {
            self::CronLancer => 'Lancer maintenant',
            self::CronSuspendre => 'Mettre en pause',
            self::CronReprendre => 'Reprendre',
            self::MessagerieEnvoyer => 'Envoyer un message',
            self::MessagerieArreter => 'Arrêter la réponse',
            self::MessagerieArchiver => 'Archiver la conversation',
            self::VaultCreer => 'Ajouter au coffre',
            self::VaultModifier => 'Modifier un élément du coffre',
            self::VaultSupprimer => 'Supprimer un élément du coffre',
        };
    }

    /**
     * La messagerie n'exige ni reconfirmation TOTP ni boîte de confirmation (décision produit, lot 13) :
     * la 2FA de connexion suffit.
     */
    public function messagerie(): bool
    {
        return str_starts_with($this->value, 'messagerie.');
    }

    /** Actions du coffre : leurs valeurs secrètes ne sont jamais journalisées ni gardées dans la confirmation. */
    public function coffre(): bool
    {
        return str_starts_with($this->value, 'vault.');
    }

    /**
     * Arguments attendus par le pont (validation côté Laravel avant envoi ; le pont revalide).
     *
     * @return array<string, list<string>>
     */
    public function regles(): array
    {
        $texte = ['string', 'max:4000', 'not_regex:/\x00/'];

        return match ($this) {
            self::CronLancer, self::CronSuspendre, self::CronReprendre => [
                'job_id' => ['required', 'string', 'regex:/^[a-f0-9]{12}$/'],
            ],
            self::MessagerieEnvoyer => [
                'interlocuteur' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
                'texte' => ['required', 'string', 'max:8000', 'not_regex:/\x00/'],
                'conversation' => ['nullable', 'string', 'regex:/^c_[a-f0-9]{16}$/'],
            ],
            self::MessagerieArreter, self::MessagerieArchiver => [
                'conversation' => ['required', 'string', 'regex:/^c_[a-f0-9]{16}$/'],
            ],
            self::VaultCreer => [
                'profil' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
                'kind' => ['required', 'string', 'in:login,payment,address'],
                'label' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
                'origin' => ['required', 'string', 'max:300', 'regex:'.self::ORIGINE],
                'identifier_type' => ['required_if:kind,login', 'nullable', 'string', 'in:email,phone,username'],
                'identifier' => ['required_if:kind,login', 'nullable', 'string', 'max:254', 'not_regex:/[\x00-\x1f\x7f]/'],
                ...self::reglesSecret(),
            ],
            self::VaultModifier => [
                'profil' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
                'handle' => ['required', 'string', 'regex:/^vault_[a-f0-9]{12}$/'],
                'label' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1f\x7f]/'],
                'origin' => ['nullable', 'string', 'max:300', 'regex:'.self::ORIGINE],
                'identifier_type' => ['nullable', 'string', 'in:email,phone,username'],
                'identifier' => ['nullable', 'string', 'max:254', 'not_regex:/[\x00-\x1f\x7f]/'],
                'retirer_otp' => ['nullable', 'boolean'],
                'vider' => ['nullable', 'array'],
                'vider.*' => ['string', 'in:otp_secret,cardholder_name,billing_postal_code,address_line2,state'],
                ...self::reglesSecret(),
            ],
            self::VaultSupprimer => [
                'profil' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
                'handle' => ['required', 'string', 'regex:/^vault_[a-f0-9]{12}$/'],
            ],
        };
    }
}
