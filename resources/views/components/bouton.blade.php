{{-- Boutons DA-7 (composants.md § 7) : principal = pilule indigo (.bouton), secondaire = .bouton .bouton-fin,
     texte = .bouton-texte, danger = .bouton-danger. Désactivé : disabled ou aria-disabled. --}}
@props(['variante' => 'principal', 'href' => null, 'type' => 'button', 'desactive' => false])
@php $classes = match ($variante) { 'secondaire' => 'bouton bouton-fin', 'texte' => 'bouton-texte', 'danger' => 'bouton-danger', default => 'bouton' }; @endphp
@if ($href !== null && ! $desactive)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@elseif ($href !== null)
    <a {{ $attributes->merge(['class' => $classes]) }} aria-disabled="true">{{ $slot }}</a>
@else
    <button type="{{ $type }}" @disabled($desactive) {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
