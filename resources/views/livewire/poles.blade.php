{{-- Pôles (DA-7, composants.md § 10 « pages sans maquette ») : titre-page → section « Direction » (vous, Hermes) →
     grille-sections, une section par pôle (h2 = nom du pôle) → une .ligne par profil (nom + rôle ; à droite : « en
     cours » ou le modèle). Le détail des skills est sur la fiche profil. --}}
@php
    use App\Interface\Nombres;
    $nomsPoles = ['dev-infra' => 'Dev & Infra', 'design' => 'Design', 'qualite' => 'Qualité', 'dev' => 'Dev web', 'business' => 'Business', 'data' => 'Data / ML', 'veille' => 'Veille & Finance', 'transverse' => 'Transverse'];
    $roles = ['executants' => 'Exécutant', 'auditeurs' => 'Auditeur', 'support' => 'Support'];
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="poles" :releve="$releve">
    <div class="titre-page">
        <h1>Pôles</h1>
        <span class="titre-page-sous">{{ Nombres::accord(count($profils), 'profil', 'profils') }} · {{ Nombres::accord(count($poles), 'pôle', 'pôles') }}</span>
    </div>

    @if ($poles === [])
        <x-vide>Aucun pôle déclaré.</x-vide>
    @else
        <div aria-label="Organigramme" role="group">
            <section class="liste" aria-labelledby="t-di">
                <h2 id="t-di">Direction</h2>
                <div class="ligne"><div class="ligne-texte"><b>Vous</b><small>Direction</small></div></div>
                @include('livewire.partials.case-org', ['p' => $hermes, 'libelle' => 'Hermes', 'role' => 'Orchestrateur : rend compte à la direction'])
            </section>

            <div class="grille-sections">
                @foreach ($poles as $pole)
                    @php $lead = $pole['lead'] ? ($profils[$pole['lead']] ?? ['profil' => $pole['lead'], 'modele' => null]) : null; @endphp
                    <section aria-labelledby="t-po-{{ $loop->index }}" wire:key="po-{{ $pole['pole'] }}">
                        <h2 id="t-po-{{ $loop->index }}">{{ $nomsPoles[$pole['pole']] ?? ucfirst(str_replace('-', ' ', $pole['pole'])) }}</h2>
                        @if ($lead)
                            @include('livewire.partials.case-org', ['p' => $lead, 'role' => 'Manager'])
                        @else
                            <div class="ligne"><div class="ligne-texte"><b>Sans manager</b><small>rend compte à Hermes</small></div></div>
                        @endif
                        @foreach ($roles as $cle => $role)
                            @foreach ($pole[$cle] ?? [] as $membre)
                                @include('livewire.partials.case-org', ['p' => $profils[$membre] ?? ['profil' => $membre, 'modele' => null], 'role' => $role])
                            @endforeach
                        @endforeach
                    </section>
                @endforeach
                @if ($horsPole !== [])
                    <section aria-labelledby="t-po-hors">
                        <h2 id="t-po-hors">Hors pôle</h2>
                        @foreach ($horsPole as $p)
                            @include('livewire.partials.case-org', ['p' => $p, 'role' => 'Sans pôle, rend compte à Hermes'])
                        @endforeach
                    </section>
                @endif
            </div>
        </div>
        <p class="histo-detail">Un clic sur un nom ouvre la fiche du profil (skills actifs, modèle, mémoire).@if ($communs) Skills communs à tous : {{ implode(', ', $communs) }}.@endif</p>
    @endif
</x-coque>
</div>
