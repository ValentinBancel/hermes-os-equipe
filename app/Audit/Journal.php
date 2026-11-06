<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Écrit une ligne du journal d'audit. Ne jamais passer de secret dans $contexte
 * (mot de passe, code TOTP, code de récupération).
 */
final class Journal
{
    /**
     * @param  array<string, mixed>  $contexte
     */
    public function ecrire(
        string $evenement,
        string $statut = 'ok',
        ?User $user = null,
        ?string $identifiant = null,
        array $contexte = [],
    ): AuditLog {
        $requete = request();

        return AuditLog::create([
            'event' => $evenement,
            'status' => $statut,
            'user_id' => $user?->getKey(),
            'identifier' => $identifiant !== null ? Str::limit($identifiant, 250, '') : $user?->email,
            'ip_address' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : $requete->ip(),
            'user_agent' => Str::limit((string) $requete->userAgent(), 250, '') ?: null,
            'context' => $contexte === [] ? null : $contexte,
        ]);
    }
}
