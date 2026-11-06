<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Hermes relié à distance (L4, R4) : un seul actif à la fois. Le secret est chiffré par APP_KEY (R6).
 */
#[Table('pont_instances')]
#[Fillable(['instance_id', 'secret', 'nom', 'hote', 'version', 'profils', 'latence_ms', 'dernier_contact_le',
    'dernier_releve_le', 'revoquee_le', 'relie_par'])]
#[Hidden(['secret'])]
class PontInstance extends Model
{
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'profils' => 'array',
            'dernier_contact_le' => 'datetime',
            'dernier_releve_le' => 'datetime',
            'revoquee_le' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoquee_le');
    }

    public static function active(): ?self
    {
        return self::query()->active()->latest('id')->first();
    }
}
