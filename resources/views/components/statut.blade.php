{{-- Plaque de statut : forme + mot + couleur (jamais la couleur seule). --}}
@props(['forme', 'texte', 'couleur' => null])
<span {{ $attributes->merge(['class' => 'etat '.($couleur ?? \App\Interface\Libelles::couleur($forme))]) }}><x-icone :nom="$forme" class="size-2.5" /><span>{{ $texte }}</span></span>
