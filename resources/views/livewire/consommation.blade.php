{{-- Consommation (DA-7, composants.md § 10 « pages sans maquette ») : titre-page + période en titre-page-sous →
     chiffre-grand + histo des 7 derniers jours → listes denses (profil, modèle, source) en .ligne. Pas d'autre graphique.
     M8 : hors cache d'abord ; un jour sans données n'est pas un zéro. D8 : barre au-delà de l'échelle signalée. --}}
@php
    use App\Interface\Libelles;
    use App\Interface\Nombres;
    use App\Interface\Temps;
    $jours7 = array_slice($parJour, -7);
    $ech7 = Nombres::echelle(array_column($jours7, 'valeur'));
    $max7 = max(1, (float) $ech7['echelle']);
    $periodes = ['7j' => Libelles::insecables('7 j'), '30j' => Libelles::insecables('30 j'), 'mois' => 'mois en cours'];
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="consommation" :releve="$releve">
    <div class="titre-page">
        <h1>Consommation</h1>
        <nav class="titre-page-sous" aria-label="Période">
            @foreach ($periodes as $cle => $lib)
                @if ($periode === $cle)<b aria-current="page">{{ $lib }}</b>@else<a href="{{ route('consommation', $cle === '30j' ? [] : ['periode' => $cle]) }}">{{ $lib }}</a>@endif
                @if (! $loop->last)<span class="separateur" aria-hidden="true">·</span>@endif
            @endforeach
        </nav>
    </div>

    @if ($c === [])
        <x-vide>Données indisponibles.</x-vide>
    @else
        <div class="grille-sections">
            <section aria-labelledby="t-to">
                <h2 id="t-to">Tokens</h2>
                <div class="chiffre-grand"><b>{{ Nombres::tokens($totalHc, true) }}</b><span>hors cache, {{ $periode === 'mois' ? 'mois en cours' : Libelles::insecables($c['periode_jours'].' j') }}</span></div>
                @if ($premier === null)
                    <div class="etat-vide"><p>Aucune session sur la période.</p></div>
                @else
                    <div class="histo" role="img" aria-label="Tokens hors cache, 7 derniers jours">
                        @foreach ($jours7 as $j)
                            @php $vide = $j['sessions'] === 0 && $j['valeur'] === 0; @endphp
                            <div><i class="histo-h-{{ (int) round(min(100, max(0, $j['valeur'] / $max7 * 100))) }}" title="{{ Temps::date($j['date'].' 12:00') }} · {{ $vide ? 'pas de données' : Nombres::tokens($j['valeur'], true) }}{{ $j['valeur'] > $max7 ? ' (hors échelle)' : '' }}"></i></div>
                        @endforeach
                    </div>
                    <div class="histo-jours" aria-hidden="true">@foreach ($jours7 as $j)<span>{{ substr($j['date'], 8, 2) }}</span>@endforeach</div>
                    <table class="sr-only"><caption>Tokens hors cache, 7 derniers jours</caption><thead><tr><th scope="col">Jour</th><th scope="col">Tokens hors cache</th></tr></thead><tbody>@foreach ($jours7 as $j)<tr><td>{{ Temps::date($j['date'].' 12:00') }}</td><td>{{ $j['sessions'] || $j['valeur'] ? Nombres::tokens($j['valeur'], true) : 'pas de données' }}</td></tr>@endforeach</tbody></table>
                @endif
                <p class="histo-detail">Cache : {{ Nombres::tokens($cache, true) }}@if ($totalHc + $cache > 0) ({{ Nombres::pourcent($cache / ($totalHc + $cache) * 100) }} du volume)@endif · {{ Nombres::accord((int) ($total['sessions'] ?? 0), 'session', 'sessions') }}@if ($pic) · pic : {{ Nombres::tokens($pic['valeur'], true) }} le {{ Temps::date($pic['date'].' 12:00') }}@endif</p>
                <p class="histo-detail">≈&nbsp;{{ Nombres::dollars((float) ($total['cout_estime_usd'] ?? 0)) }} : estimation calculée à partir d'une grille de prix (les coûts enregistrés par Hermes sont à 0&nbsp;$, abonnement).</p>
            </section>

            <section aria-labelledby="t-pr">
                <h2 id="t-pr">Par profil</h2>
                @forelse ($profils as $p)
                    <div class="ligne" wire:key="p-{{ $p['nom'] }}">
                        <div class="ligne-texte"><b><a href="{{ route('poles.profil', $p['nom']) }}">{{ $p['nom'] }}</a></b><small>{{ Nombres::accord($p['sessions'], 'session', 'sessions') }} · {{ Nombres::pourcent($p['part']) }} du hors cache · cache {{ Nombres::tokens($p['cache']) }} · ≈&nbsp;{{ Nombres::dollars($p['cout']) }}</small></div>
                        <time>{{ Nombres::tokens($p['hors_cache'], true) }}</time>
                    </div>
                @empty
                    <div class="etat-vide"><p>Aucune donnée.</p></div>
                @endforelse
                <p class="histo-detail">{{ $c['note'] }} · <a href="{{ route('consommation.sessions') }}">Sessions</a></p>
            </section>

            @include('livewire.partials.table-conso', ['lignes' => $modeles, 'titre' => 'Par modèle', 'id' => 't-mo', 'cache' => true])
            @include('livewire.partials.table-conso', ['lignes' => $sources, 'titre' => 'Par source', 'id' => 't-so', 'cache' => false])
        </div>
    @endif
</x-coque>
</div>
