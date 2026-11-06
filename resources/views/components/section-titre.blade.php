{{-- Titre de section à filet (DA-7, composants.md § 4 et § 11 : .section-titre) ; lien ou compteur à droite. --}}
@props(['id', 'niveau' => 'h2'])
<{{ $niveau }} id="{{ $id }}" {{ $attributes->merge(['class' => 'section-titre']) }}><span>{{ $slot }}</span>@isset($droite) <span>{{ $droite }}</span>@endisset</{{ $niveau }}>
