@props(['href'])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex min-h-11 items-center text-donnee whitespace-nowrap']) }}>{{ $slot }}</a>
