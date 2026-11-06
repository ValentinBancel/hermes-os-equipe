<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Code d'appairage à usage unique (R4) : seul son SHA-256 est stocké. */
#[Table('pont_codes')]
#[Fillable(['code_hash', 'user_id', 'essais_faux', 'expire_le', 'utilise_le', 'invalide_le'])]
class PontCode extends Model
{
    protected function casts(): array
    {
        return ['expire_le' => 'datetime', 'utilise_le' => 'datetime', 'invalide_le' => 'datetime'];
    }

    public function utilisable(): bool
    {
        return $this->utilise_le === null && $this->invalide_le === null && $this->expire_le->isFuture();
    }
}
