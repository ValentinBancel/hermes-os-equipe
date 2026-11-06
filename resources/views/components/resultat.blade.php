{{-- Résultat d'action (composants.md § 9, message flash) : .ok vert, .erreur rouge ; reste affiché jusqu'à fermeture. --}}
@props(['resultat', 'attente' => false])
@if ($resultat)
    <p @class(['flash', 'ok' => $resultat['ok'], 'erreur' => ! $resultat['ok']]) role="{{ $resultat['ok'] ? 'status' : 'alert' }}">
        <span>{{ $resultat['message'] }}@if (! empty($resultat['lien'])) <a href="{{ $resultat['lien'] }}">Ouvrir</a>@endif</span>
        <button type="button" class="bouton-texte" wire:click="fermerResultat">Fermer</button>
    </p>
@endif
{{-- Pilote distant (L4) : action en file, suivie toutes les 2 s même si le message est fermé. --}}
@if ($attente)
    <span hidden wire:poll.2s="suivreAction"></span>
@endif
