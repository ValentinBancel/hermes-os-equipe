<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Action en file pour le pont distant (R8) : en_attente → envoyee → terminee (ou expiree). */
#[Table('pont_actions')]
#[Fillable(['action', 'instance_id', 'donnees', 'acteur', 'statut', 'resultat', 'expire_le', 'envoyee_le', 'terminee_le'])]
class PontAction extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'resultat' => 'array',
            'expire_le' => 'datetime',
            'envoyee_le' => 'datetime',
            'terminee_le' => 'datetime',
        ];
    }
}
