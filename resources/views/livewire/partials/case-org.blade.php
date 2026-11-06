{{-- Ligne de profil (Pôles, DA-7 § 10) : nom (lien vers la fiche) + rôle ; rôle · modèle court ; à droite « en cours »
     (lien vers les sessions) si une session tourne. --}}
@php
    $modele = isset($p['modele']) ? preg_replace('/^claude-/', '', (string) $p['modele']) : null;
    $session = $enCours[$p['profil']] ?? null;
@endphp
<div class="ligne" wire:key="pr-{{ $p['profil'] }}-{{ $role }}">
    <div class="ligne-texte"><b><a href="{{ route('poles.profil', $p['profil']) }}">{{ $libelle ?? $p['profil'] }}</a></b><small>{{ $role }}@if ($modele) · {{ $modele }}@endif</small></div>
    @if ($session)
        <a class="pastille-action" href="{{ route('consommation.sessions') }}">en cours<span class="sr-only"> : session {{ $session['titre'] ?: $session['id'] }}</span></a>
    @endif
</div>
