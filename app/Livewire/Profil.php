<?php

namespace App\Livewire;

use App\Hermes\HermesClient;
use App\Interface\Decisions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Fiche profil (/poles/{profil}) : pôle, rôle, modèle, skills actifs, sessions en cours.
 */
class Profil extends Page
{
    protected string $actif = 'poles';

    #[Locked]
    public string $profil;

    public function mount(string $profil): void
    {
        $this->profil = $profil;
    }

    public function render(): View
    {
        $releve = $this->releve();
        $d = $releve->donnees(fn (HermesClient $c) => $c->poles());
        $p = collect($d['profils'] ?? [])->firstWhere('profil', $this->profil);
        if ($p === null && ! $releve->fige()) {
            abort(404);
        }

        $sessions = array_values(array_filter(
            $releve->donnees(fn (HermesClient $c) => $c->sessions())['sessions'] ?? [],
            fn (array $s) => ($s['profil'] ?? null) === $this->profil,
        ));
        $enCours = Decisions::enCours($sessions, CarbonImmutable::now());
        // « Écrire à » seulement pour un interlocuteur de la liste fermée servie par le pont.
        $ecrire = config('hermes-os.messagerie') && in_array($this->profil, array_column(
            $releve->donnees(fn (HermesClient $c) => $c->messagerie())['interlocuteurs'] ?? [], 'id'), true);

        return $this->page('livewire.profil', ['p' => $p ?? [], 'enCours' => $enCours, 'ecrire' => $ecrire])->title($this->profil.' — hermes-os');
    }
}
