<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Journal d'audit : une ligne par événement de sécurité (connexion, TOTP, déconnexion…)
 * et par action d'écriture. Ajout seul, avec une unique exception : une action est écrite
 * en `pending` avant l'appel au pont, puis complétée une fois (`ok` ou `failed`) via terminer().
 * Aucune autre modification, aucune suppression.
 *
 * @property int $id
 * @property string $event
 * @property string $status
 * @property int|null $user_id
 * @property string|null $identifier
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const STATUTS_FINAUX = ['ok', 'failed'];

    protected $table = 'audit_log';

    protected $fillable = ['event', 'status', 'user_id', 'identifier', 'ip_address', 'user_agent', 'context'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AuditLog $ligne) {
            $transitionAutorisee = $ligne->getOriginal('status') === 'pending'
                && in_array($ligne->status, self::STATUTS_FINAUX, true)
                && array_diff(array_keys($ligne->getDirty()), ['status', 'context']) === [];

            if (! $transitionAutorisee) {
                throw new LogicException('Le journal d\'audit est en ajout seul.');
            }
        });
        static::deleting(fn () => throw new LogicException('Le journal d\'audit est en ajout seul.'));
    }

    /**
     * Complète une ligne `pending` (une seule fois) : statut final et résultat ajouté au contexte.
     *
     * @param  array<string, mixed>  $resultat
     */
    public function terminer(string $statut, array $resultat = []): void
    {
        $this->status = $statut;
        $this->context = array_merge($this->context ?? [], ['resultat' => $resultat]);
        $this->save();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
