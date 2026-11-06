<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Dernière réponse du pont pour une lecture en liste blanche (R7), clé = chemin /v1/…?requête. */
#[Table('pont_releves')]
#[Fillable(['cle', 'statut', 'donnees', 'recu_le'])]
class PontReleve extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['donnees' => 'array', 'recu_le' => 'datetime'];
    }
}
