{{-- Journal d'audit (DA-7, page d'objet de Compte) : lecture seule ; filtre par type en titre-page-sous (navigation,
     pas de select) ; une .ligne par entrée (type en b ; résultat · détail · IP en small ; date en time) ; échec = rouge. --}}
@php use App\Interface\Audit; use App\Interface\Temps; @endphp
<div>
<x-coque actif="compte" :releve="$releve">
    <x-page-titre titre="Compte" objet="Journal d’audit" :retour="route('compte')">
        <x-slot:sous>Lecture seule · 30 derniers jours</x-slot:sous>
    </x-page-titre>
    <nav class="titre-page-sous" aria-label="Filtrer par type">
        @foreach (\App\Livewire\Journal::TYPES as $cle => [$lib])
            @if ($type === $cle)<b aria-current="page">{{ $lib }}</b>@else<a href="{{ route('compte.journal', $cle === 'tous' ? [] : ['type' => $cle]) }}">{{ $lib }}</a>@endif
            @if (! $loop->last)<span class="separateur" aria-hidden="true">·</span>@endif
        @endforeach
    </nav>
    <section class="liste" aria-label="Journal d'audit, du plus récent au plus ancien">
        @forelse ($lignes as $l)
            @php [$typeL, $detail] = Audit::decrire($l); [$res, $forme] = Audit::resultat($l->status); $echec = in_array($l->status, ['failed', 'blocked'], true); @endphp
            <div class="ligne" wire:key="j-{{ $l->id }}">
                <div class="ligne-texte"><b>{{ $typeL }}</b><small class="[overflow-wrap:anywhere]"><span @class(['rouge' => $echec])>{{ $forme === 's-ok' ? 'OK' : $res }}</span> · {{ $detail }}@if ($l->ip_address) · {{ $l->ip_address }}@endif</small></div>
                <x-temps :t="$l->created_at" format="absolu" />
            </div>
        @empty
            <div class="etat-vide"><p>Aucune entrée sur 30 jours.</p></div>
        @endforelse
    </section>
    @if ($lignes->hasPages())
        <nav class="formulaire-actions" aria-label="Pages du journal">
            @if (! $lignes->onFirstPage())<button type="button" wire:click="previousPage" class="bouton-texte">Entrées plus récentes</button>@endif
            @if ($lignes->hasMorePages())<button type="button" wire:click="nextPage" class="bouton-texte">Entrées plus anciennes</button>@endif
        </nav>
    @endif
</x-coque>
</div>
