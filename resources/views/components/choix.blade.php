{{-- Liste à double filet DA-N3 (appearance:none + chevron SVG du design system, § 13). --}}
@props(['enveloppe' => ''])
<select {{ $attributes->merge(['class' => 'champ '.$enveloppe]) }}>{{ $slot }}</select>
