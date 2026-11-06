{{-- Document HTML commun (pages de la console, écrans de connexion, erreurs), DA-7 « Épure ».
     Aucun script ni style en ligne (CSP à nonce). Police du premier rendu préchargée : Outfit (design-system/fonts/,
     présente au manifeste Vite car référencée par tokens.css). --}}
@props(['title' => 'Connexion'])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="preload" href="{{ \Illuminate\Support\Facades\Vite::asset('design-system/fonts/outfit-latin-wght.woff2') }}" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <x-sprite />
    {{ $slot }}
</body>
</html>
