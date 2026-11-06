{{-- Planifiées (DA-7, composants.md § 5) : titre « N tâches · M suspendue(s) », liste dense en .tache, groupes dans
     l'ordre En échec / Actives / En pause / Désactivées. Actions en ligne (arbitrage cto du 03/11) :
     « Lancer », « Suspendre », « Reprendre » (confirmation + TOTP, comme sur la fiche) en secondaire, « Ouvrir » en
     principal, dans une colonne de largeur fixe alignée à droite. --}}
@php
    use App\Interface\Nombres;
    $suspendues = count($groupes['pause']);
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="planifiees" :releve="$releve">
    <x-resultat :resultat="$resultat" :attente="$enAttente !== null" />
    <div class="titre-page">
        <h1>Planifiées</h1>
        <span class="titre-page-sous">{{ Nombres::accord($total, 'tâche', 'tâches') }}@if ($suspendues) · {{ Nombres::accord($suspendues, 'suspendue', 'suspendues') }}@endif</span>
    </div>

    @if ($total === 0)
        <div class="etat-vide"><b>Aucune tâche planifiée</b><p>Les tâches cron de Hermes apparaîtront ici dès qu'il en aura.</p></div>
    @else
        <div class="liste" role="table" aria-label="Tâches planifiées">
            <div class="tache tache-entete" role="row"><span role="columnheader">Tâche</span><span role="columnheader">Fréquence</span><span role="columnheader">Dernière exécution</span><span role="columnheader">Prochaine</span><span class="tache-actions" role="columnheader"><span class="sr-only">Actions</span></span></div>
            @foreach (['echec', 'active', 'pause', 'off'] as $g)
                @include('livewire.partials.table-taches', ['taches' => $groupes[$g], 'groupe' => $g])
            @endforeach
        </div>
    @endif

    <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
</x-coque>
</div>
