@props(['nom', 'class' => 'size-5'])
<svg {{ $attributes->merge(['class' => $class.' shrink-0']) }} aria-hidden="true" focusable="false"><use href="#{{ $nom }}"/></svg>
