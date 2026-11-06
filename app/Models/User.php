<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Compte de connexion à la console (ADR 0003). Plusieurs comptes (L3), sans rôle : tout compte actif a tous les
 * droits. Le premier est créé par `php artisan hermes-os:compte`, les suivants par la page Compte ou la même commande.
 * Pas de suppression : un compte se désactive (`desactive_le`).
 *
 * @property ?Carbon $desactive_le
 * @property ?Carbon $derniere_activite_le
 * @property ?int $cree_par
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, TwoFactorAuthenticatable;

    /** « Connecté » = activité depuis moins de 5 minutes (décision C3). */
    public const MINUTES_PRESENCE = 5;

    /** Écriture de `derniere_activite_le` au plus une fois par minute. */
    public const SECONDES_ENTRE_ACTIVITES = 60;

    /** @return HasMany<ReseauDeConfiance, $this> */
    public function reseauxDeConfiance(): HasMany
    {
        return $this->hasMany(ReseauDeConfiance::class);
    }

    /** @return HasMany<AppareilDeConfiance, $this> */
    public function appareilsDeConfiance(): HasMany
    {
        return $this->hasMany(AppareilDeConfiance::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /** @param  Builder<User>  $query */
    #[Scope]
    protected function actifs(Builder $query): void
    {
        $query->whereNull('desactive_le');
    }

    /** @param  Builder<User>  $query */
    #[Scope]
    protected function connectes(Builder $query): void
    {
        $query->whereNull('desactive_le')->where('derniere_activite_le', '>=', now()->subMinutes(self::MINUTES_PRESENCE));
    }

    public function estActif(): bool
    {
        return $this->desactive_le === null;
    }

    public function estConnecte(): bool
    {
        return $this->estActif()
            && $this->derniere_activite_le !== null
            && $this->derniere_activite_le->gte(now()->subMinutes(self::MINUTES_PRESENCE));
    }

    /** Note l'activité du compte, au plus une écriture par minute (appelé à chaque requête authentifiée). */
    public function noterActivite(): void
    {
        if ($this->derniere_activite_le === null || $this->derniere_activite_le->lte(now()->subSeconds(self::SECONDES_ENTRE_ACTIVITES))) {
            $this->forceFill(['derniere_activite_le' => now()])->saveQuietly();
        }
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'mot_de_passe_provisoire' => 'boolean',
            'desactive_le' => 'datetime',
            'derniere_activite_le' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
