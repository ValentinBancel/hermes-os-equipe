<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Navigateur reconnu par un cookie chiffré « id|jeton » ; seule l'empreinte du jeton est en base.
 */
#[Table('appareils_de_confiance')]
#[Fillable(['jeton_hash', 'libelle', 'ip_creation', 'derniere_ip', 'utilise_le', 'expire_le'])]
#[Hidden(['jeton_hash'])]
class AppareilDeConfiance extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utilise_le' => 'datetime',
            'expire_le' => 'datetime',
        ];
    }
}
