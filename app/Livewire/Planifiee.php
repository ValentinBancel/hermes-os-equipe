<?php

namespace App\Livewire;

use App\Hermes\HermesClient;
use App\Livewire\Concerns\ActionsTache;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Fiche d'une tâche planifiée (/planifiees/{id}, /planifiees/{id}/sorties/{sortie}) : erreur en clair + brute repliée,
 * dernière sortie (40 lignes puis « Afficher tout »), historique, actions Lancer / Pause / Reprendre.
 */
class Planifiee extends Page
{
    use ActionsTache;

    protected string $actif = 'planifiees';

    #[Locked]
    public string $id;

    #[Locked]
    public ?string $sortie = null;

    public bool $sortieComplete = false;

    public function mount(string $id, ?string $sortie = null): void
    {
        $this->id = $id;
        $this->sortie = $sortie;
    }

    /** @return array<string, mixed> */
    private function tache(): array
    {
        return $this->releve()->donnees(fn (HermesClient $c) => $c->planifiee($this->id));
    }

    public function render(): View
    {
        $t = $this->tache();
        $sorties = $t['sorties'] ?? [];
        usort($sorties, fn (array $a, array $b) => strcmp((string) $b['nom'], (string) $a['nom']));
        $nomSortie = $this->sortie ?? ($sorties[0]['nom'] ?? null);
        if ($this->sortie !== null && $t !== [] && ! in_array($this->sortie, array_column($sorties, 'nom'), true)) {
            abort(404);
        }
        $sortie = $nomSortie === null ? [] : $this->releve()->donnees(fn (HermesClient $c) => $c->sortie($this->id, $nomSortie));

        $historique = $t['historique'] ?? [];
        usort($historique, fn (array $a, array $b) => strcmp((string) $b['demarre_a'], (string) $a['demarre_a']));

        return $this->page('livewire.planifiee', [
            't' => $t,
            'sorties' => $sorties,
            'nomSortie' => $nomSortie,
            'contenuSortie' => $sortie,
            'historique' => array_slice($historique, 0, 20),
        ])->title(($t['nom'] ?? $this->id).' — hermes-os');
    }

    public function demanderLancement(): void
    {
        $this->confirmerLancement($this->id, $this->tache());
    }

    public function demanderPause(): void
    {
        $this->confirmerPause($this->id, $this->tache());
    }

    public function demanderReprise(): void
    {
        $this->confirmerReprise($this->id, $this->tache());
    }
}
