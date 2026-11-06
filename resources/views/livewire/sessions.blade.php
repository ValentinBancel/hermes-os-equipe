{{-- Sessions (DA-7, page d'objet de Consommation : composants.md § 10) : métadonnées seulement, une .ligne par session
     (titre en b ; modèle · profil · source · début en small, plus le dernier signe de vie d'une session active ; durée ou
     « en cours » et tokens en time). « État : en cours » = cible du compteur « en cours » de l'accueil. --}}
@php use App\Interface\Nombres; use App\Interface\Temps; @endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="consommation" :releve="$releve">
    <div @class(['fige' => $fige])>
        <x-page-titre titre="Consommation" objet="Sessions" :retour="route('consommation')">
            <x-slot:sous>{{ $etat === 'en-cours' ? 'Sessions en cours (activité depuis moins de 15 min)' : '100 dernières sessions' }} · métadonnées seulement, aucune transcription</x-slot:sous>
        </x-page-titre>
        <div class="formulaire-actions">
            <div class="champ">
                <label for="s-profil">Profil</label>
                <select id="s-profil" wire:model.live="profil">
                    <option value="">tous</option>
                    @foreach ($profils as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
            </div>
            <div class="champ">
                <label for="s-etat">État</label>
                <select id="s-etat" wire:model.live="etat">
                    <option value="">toutes</option>
                    <option value="en-cours">en cours</option>
                </select>
            </div>
        </div>
        <section class="liste" aria-label="Sessions, de la plus récente à la plus ancienne">
            @forelse ($sessions as $s)
                <div class="ligne" wire:key="s-{{ $s['id'] }}">
                    <div class="ligne-texte"><b class="[overflow-wrap:anywhere]">{{ $s['titre'] ?: 'Sans titre' }}</b><small>{{ $s['modele'] }} · {{ $s['profil'] }} · {{ $s['source'] }} · <x-temps :t="$s['demarre_a']" format="absolu" />@if (! $s['duree_secondes'] && ! empty($s['derniere_activite'])) · signe de vie <x-temps :t="$s['derniere_activite']" :fige="$fige" />@endif</small></div>
                    <time>{{ $s['duree_secondes'] ? Temps::duree((int) $s['duree_secondes']) : 'en cours' }} · {{ Nombres::tokens((int) $s['tokens'], true) }}</time>
                </div>
            @empty
                <div class="etat-vide"><p>{{ $etat === 'en-cours' ? 'Aucune session en cours.' : 'Aucune session.' }}</p></div>
            @endforelse
        </section>
    </div>
</x-coque>
</div>
