@php use App\Interface\Nombres; @endphp
{{-- Liste dense DA-7 (composants.md § 10, Consommation) : une .ligne par modèle ou source, volume hors cache en time. --}}
<section aria-labelledby="{{ $id }}">
    <h2 id="{{ $id }}">{{ $titre }}</h2>
    @forelse ($lignes as $l)
        <div class="ligne">
            <div class="ligne-texte"><b class="[overflow-wrap:anywhere]">{{ $l['nom'] }}</b><small>{{ Nombres::accord($l['sessions'], 'session', 'sessions') }}@if ($cache) · cache {{ Nombres::tokens($l['cache']) }}@endif</small></div>
            <time>{{ Nombres::tokens($l['hors_cache'], true) }}</time>
        </div>
    @empty
        <div class="etat-vide"><p>Aucune donnée.</p></div>
    @endforelse
</section>
