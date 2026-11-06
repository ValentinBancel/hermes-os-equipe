{{-- Fiche profil (DA-7, page d'objet : composants.md § 5 et § 10) : titre-page (objet + retour « Pôles ») →
     grille-sections : sessions en cours (une .ligne chacune) et skills actifs (une .ligne chacun). --}}
@php use App\Interface\Libelles; @endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="poles" :releve="$releve">
    <div @class(['fige' => $fige])>
        <x-page-titre titre="Pôles" :objet="$profil" :retour="route('poles')">
            <x-slot:sous>@if ($p){{ $p['pole'] ?? 'hors pôle' }}@if ($p['role']) · {{ $p['role'] }}@endif · {{ $p['modele'] }}@endif</x-slot:sous>
        </x-page-titre>
        @if (! empty($p['description']))<p class="histo-detail">{{ $p['description'] }}</p>@endif
        @if ($p && $ecrire)
            <div class="formulaire-actions"><a href="{{ Libelles::lienEcrire($profil, '') }}" class="bouton bouton-fin">Écrire à {{ $profil }}</a></div>
        @endif
        <div class="grille-sections">
            <section aria-labelledby="t-ca">
                <h2 id="t-ca">En cours <span class="titre-page-sous">{{ count($enCours) }}</span></h2>
                @forelse ($enCours as $c)
                    <div class="ligne">
                        <div class="ligne-texte"><b><a href="{{ route('consommation.sessions') }}">{{ $c['titre'] ?: 'Session '.$c['source'] }}</a></b><small>{{ $c['source'] }} · depuis <x-temps :t="$c['demarre_a']" format="absolu" /></small></div>
                        <time>en cours</time>
                    </div>
                @empty
                    <div class="etat-vide"><p>Aucune session en cours.</p></div>
                @endforelse
            </section>
            <section aria-labelledby="t-sk">
                <h2 id="t-sk">Skills actifs <span class="titre-page-sous">{{ count($p['skills_actifs'] ?? []) }}</span></h2>
                @forelse ($p['skills_actifs'] ?? [] as $s)
                    <div class="ligne"><div class="ligne-texte"><b class="[overflow-wrap:anywhere]">{{ $s }}</b></div></div>
                @empty
                    <div class="etat-vide"><p>Aucun skill actif.</p></div>
                @endforelse
            </section>
        </div>
    </div>
</x-coque>
</div>
