{{-- Ligne de la liste Planifiées (composants.md § 5) : nom, fréquence en clair, dernière exécution (point + mot ;
     rouge réservé à l'échec), prochaine, actions (« Lancer » / « Suspendre » / « Reprendre » secondaires, « Ouvrir » principal). En pause / désactivée = .tache.suspendue + pastille. --}}
@php
    use App\Interface\Libelles;
    use App\Interface\Planification;
    use Illuminate\Support\Str;
@endphp
@foreach ($taches as $t)
    @php
        [$st, $forme] = Libelles::dernierStatut($t['dernier_statut']);
        $classe = match ($forme) { 's-arret' => 'echec', 's-ok' => 'reussie', default => '' };
    @endphp
    <div @class(['tache', 'suspendue' => in_array($groupe, ['pause', 'off'], true)]) role="row" wire:key="t-{{ $t['id'] }}">
        <div class="tache-nom" role="cell">
            <b><a href="{{ route('planifiees.fiche', $t['id']) }}">{{ $t['nom'] }}</a>@if (in_array($groupe, ['pause', 'off'], true))<span class="pastille">{{ Str::lower(Planification::etat($t)) }}</span>@endif</b>
            @if (! empty($t['profil']))<small>profil {{ $t['profil'] }}</small>@endif
        </div>
        <span class="tache-frequence" role="cell" title="{{ $t['planning'] }}">{{ Planification::enClair($t['planning']) }}</span>
        <div class="tache-derniere {{ $classe }}" role="cell">
            @if ($t['derniere_execution'])
                <b>{{ $forme === 's-arret' ? 'Échec' : $st }}</b><small><x-temps :t="$t['derniere_execution']" :fige="$fige" /></small>
            @else
                <b>Jamais exécutée</b>
            @endif
        </div>
        <div class="tache-prochaine" role="cell"><b>@if ($t['prochaine_execution'] && $groupe !== 'off')<x-temps :t="$t['prochaine_execution']" format="absolu" />@else—@endif</b></div>
        <span class="tache-actions" role="cell">
            @unless ($fige)
                @if ($t['actif'] ?? true)
                    <button type="button" x-data="declencheur" x-on:click="memoriser" wire:click="demanderLancement('{{ $t['id'] }}')" aria-label="Lancer {{ $t['nom'] }}">Lancer</button>
                @endif
                @if ($groupe === 'pause')
                    <button type="button" x-data="declencheur" x-on:click="memoriser" wire:click="demanderReprise('{{ $t['id'] }}')" aria-label="Reprendre {{ $t['nom'] }}">Reprendre</button>
                @elseif ($groupe !== 'off')
                    <button type="button" x-data="declencheur" x-on:click="memoriser" wire:click="demanderPause('{{ $t['id'] }}')" aria-label="Suspendre {{ $t['nom'] }}">Suspendre</button>
                @endif
            @endunless
            <a class="tache-ouvrir" href="{{ route('planifiees.fiche', $t['id']) }}" aria-label="Ouvrir {{ $t['nom'] }}">Ouvrir</a>
        </span>
    </div>
@endforeach
