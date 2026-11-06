<?php

namespace App\Livewire;

use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Journal d'audit (/compte/journal) : lecture seule, 30 derniers jours, filtre par type, 50 lignes par page.
 */
#[Title('Journal d’audit — hermes-os')]
class Journal extends Page
{
    use WithPagination;

    protected string $actif = 'compte';

    public const TYPES = [
        'tous' => ['Tous', null],
        'connexion' => ['Connexion', ['connexion.reussie', 'deconnexion', 'session.expiree', 'session.sans_totp']],
        'echec' => ['Échec de connexion', ['connexion.echec', 'connexion.verrouillage', 'connexion.refusee_verrouillage']],
        'totp' => ['Code TOTP', ['totp.%']],
        'action' => ['Action', ['action.%', 'compte.%']],
    ];

    #[Url(as: 'type', except: 'tous')]
    public string $type = 'tous';

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $filtre = self::TYPES[$this->type][1] ?? null;
        $lignes = AuditLog::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->when($filtre, fn ($q) => $q->where(function ($q) use ($filtre) {
                foreach ($filtre as $motif) {
                    str_ends_with($motif, '%') ? $q->orWhere('event', 'like', $motif) : $q->orWhere('event', $motif);
                }
            }))
            ->latest('id')
            ->paginate(50);

        return $this->page('livewire.journal', ['lignes' => $lignes]);
    }
}
