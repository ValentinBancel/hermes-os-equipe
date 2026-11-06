{{-- Un moment, toujours via App\Interface\Temps (M1) : relatif si frais, absolu si figé ; date complète au survol. --}}
@props(['t', 'fige' => false, 'format' => 'quand', 'avant' => ''])
@php
    $texte = match ($format) {
        'longue' => \App\Interface\Temps::dateLongue($t),
        'heure' => \App\Interface\Temps::heure($t),
        'absolu' => \App\Interface\Temps::absolu($t),
        'date' => \App\Interface\Temps::date($t),
        default => \App\Interface\Temps::quand($t, $fige),
    };
@endphp
<time datetime="{{ \App\Interface\Temps::iso($t) }}" title="{{ \App\Interface\Temps::complet($t) }}" {{ $attributes }}>{{ $avant }}{{ $texte }}</time>
