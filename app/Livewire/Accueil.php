<?php

namespace App\Livewire;

use App\Interface\TableauDuJour;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;

/**
 * Accueil « Le tableau du jour » (disposition 1) : bandeau du jour (3 compteurs, étiquette de date, verdict,
 * ligne d'état, frise 0-24 h), colonne « À décider » épinglée, grille Erreurs / En cours / Prochaines / Tokens.
 * Rafraîchi toutes les 5 s (ADR 0005). Premier affichage en squelettes à la taille finale (chargement différé).
 */
#[Title('Accueil — hermes-os')]
#[Lazy]
class Accueil extends Page
{
    protected string $actif = 'accueil';

    protected string $rafraichissement = '5s';

    public function placeholder(): View
    {
        return view('livewire.accueil-chargement');
    }

    public function render(): View
    {
        return $this->page('livewire.accueil', ['jour' => new TableauDuJour($this->releve())]);
    }
}
