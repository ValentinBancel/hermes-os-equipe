<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réseau (plage CIDR) depuis lequel un appareil de confiance peut se connecter sans code TOTP.
 */
#[Table('reseaux_de_confiance')]
#[Fillable(['libelle', 'plage'])]
class ReseauDeConfiance extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
