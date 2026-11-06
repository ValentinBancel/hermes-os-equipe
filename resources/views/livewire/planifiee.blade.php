{{-- Fiche tâche planifiée (DA-7, page d'objet : composants.md § 5-6) : titre-page (objet + retour) → duo : à gauche
     dernière exécution (erreur en clair + brute repliée), sortie, historique (fiche-ligne : date, durée, statut ; OK vert, échec rouge) ; à droite la section Actions
     (fiche-ligne + boutons). Aucun verre. --}}
@php
    use App\Interface\Erreurs;
    use App\Interface\Libelles;
    use App\Interface\Planification;
    use App\Interface\Temps;
    use App\Interface\Nombres;
    [$st, $forme] = Libelles::dernierStatut($t['dernier_statut'] ?? null);
    $echec = $t !== [] && Planification::enEchec($t);
    $brute = trim((string) (($t['erreur_livraison'] ?? '') ?: ($t['derniere_erreur'] ?? '')));
    $lignes = preg_split('/\R/', (string) ($contenuSortie['contenu'] ?? '')) ?: [];
    $enPause = ($t['etat'] ?? null) === 'paused';
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="planifiees" :releve="$releve">
    <x-resultat :resultat="$resultat" :attente="$enAttente !== null" />
    <div @class(['fige' => $fige])>
        <x-page-titre titre="Planifiées" :objet="$t['nom'] ?? $id" :retour="route('planifiees')">
            {{-- 390 px (dc m4 L6) : chaque segment reste d'un bloc, la coupure ne tombe qu'entre deux « · ». --}}
            <x-slot:sous><span class="whitespace-nowrap">{{ $id }}</span>@if ($t !== []) · <b class="whitespace-nowrap">{{ Planification::etat($t) }}</b> · <span class="whitespace-nowrap">{{ Planification::enClair($t['planning']) }} ({{ $t['planning'] }})</span> · <span class="whitespace-nowrap">livraison : {{ $t['livraison'] }}</span>@endif</x-slot:sous>
        </x-page-titre>

        @if ($t === [])
            <div class="etat-vide"><p>Données indisponibles : pont injoignable.</p></div>
        @else
        <div class="duo">
            <div>
                <section class="section" aria-labelledby="t-de">
                    <h2 id="t-de">Dernière exécution <span @class(['titre-page-sous', 'rouge' => $forme === 's-arret'])>{{ $st }}</span></h2>
                    @if ($t['derniere_execution'])
                        <p class="histo-detail"><x-temps :t="$t['derniere_execution']" :fige="$fige" /></p>
                    @endif
                    @if ($echec)
                        <p class="md [overflow-wrap:anywhere]">{{ Erreurs::enClair($brute, $t['dernier_statut']) }}</p>
                        @if (($t['echecs_consecutifs'] ?? 0) > 0)<p class="flash erreur">{{ Nombres::accord((int) $t['echecs_consecutifs'], 'échec consécutif', 'échecs consécutifs') }}</p>@endif
                        @if ($brute !== '')
                            <details class="ligne">
                                <summary class="bouton-texte">Erreur brute</summary>
                                <div class="md"><pre>{{ $brute }}</pre></div>
                            </details>
                        @endif
                    @elseif (! $t['derniere_execution'])
                        <div class="etat-vide"><p>Jamais exécutée.</p></div>
                    @endif
                </section>

                <section class="section" aria-labelledby="t-so">
                    <h2 id="t-so">{{ $sortie ? 'Sortie' : 'Dernière sortie' }} <span class="titre-page-sous">{{ $nomSortie }}</span></h2>
                    @if ($contenuSortie === [])
                        <div class="etat-vide"><p>Aucune sortie enregistrée.</p></div>
                    @else
                        <div class="md"><pre>{{ implode("\n", $sortieComplete ? $lignes : array_slice($lignes, 0, 40)) }}</pre></div>
                        @if (count($lignes) > 40 && ! $sortieComplete)
                            <p><button type="button" wire:click="$set('sortieComplete', true)" class="bouton-texte">Afficher tout ({{ count($lignes) }} lignes)</button></p>
                        @endif
                        @if ($contenuSortie['tronque'])<p class="champ-aide">Sortie tronquée par le pont.</p>@endif
                    @endif
                    @if (count($sorties) > 1)
                        <details>
                            <summary class="bouton-texte">Sorties précédentes ({{ count($sorties) }})</summary>
                            @foreach ($sorties as $s)
                                <div class="ligne">
                                    <div class="ligne-texte"><b><a href="{{ route('planifiees.sortie', [$id, $s['nom']]) }}" @if ($s['nom'] === $nomSortie) aria-current="page" @endif>{{ $s['nom'] }}</a></b></div>
                                    <time>{{ Nombres::octets((int) $s['octets']) }}</time>
                                </div>
                            @endforeach
                        </details>
                    @endif
                </section>

                <section class="section" aria-labelledby="t-hi">
                    <h2 id="t-hi">Historique <span class="titre-page-sous">{{ count($historique) }}</span></h2>
                    @forelse ($historique as $h)
                        @php
                            $d = Temps::lire($h['demarre_a']); $f = Temps::lire($h['termine_a']);
                            $ok = $h['statut'] === 'completed' && $h['livraison'] !== 'failed';
                            $libH = $ok ? 'OK' : ($h['livraison'] === 'failed' ? 'Échec de livraison' : ($h['statut'] === 'running' ? 'En cours' : 'Échec'));
                        @endphp
                        <div class="fiche-ligne" wire:key="h-{{ $h['id'] }}">
                            <span><x-temps :t="$h['demarre_a']" format="absolu" /></span>
                            <span>{{ $d && $f ? Temps::duree((int) $d->diffInSeconds($f)) : '—' }}</span>
                            <span @class(['ok' => $ok, 'rouge' => ! $ok && $h['statut'] !== 'running'])>{{ $libH }}</span>
                        </div>
                    @empty
                        <div class="etat-vide"><p>Aucune exécution enregistrée.</p></div>
                    @endforelse
                </section>
            </div>

            <aside class="section" aria-labelledby="t-ac">
                <h2 id="t-ac">Actions</h2>
                <div class="fiche-ligne">
                    <span>Prochaine exécution</span>
                    @if ($t['prochaine_execution'] && ! $enPause && $t['actif'])
                        <span>{{ Temps::heure($t['prochaine_execution']) }} <small>{{ Temps::jour($t['prochaine_execution']) }}</small></span>
                    @else
                        <span>{{ $enPause ? 'Aucune : tâche en pause.' : 'Aucune.' }}</span>
                    @endif
                </div>
                <div class="formulaire-actions">
                    <x-bouton id="btn-lancer" x-data="declencheur" x-on:click="memoriser" wire:click="demanderLancement" :desactive="$fige || ! $t['actif']">Lancer maintenant…</x-bouton>
                    @if ($enPause)
                        <x-bouton id="btn-reprendre" variante="secondaire" x-data="declencheur" x-on:click="memoriser" wire:click="demanderReprise" :desactive="$fige">Reprendre…</x-bouton>
                    @elseif ($t['actif'])
                        <x-bouton id="btn-pause" variante="secondaire" x-data="declencheur" x-on:click="memoriser" wire:click="demanderPause" :desactive="$fige">Mettre en pause…</x-bouton>
                    @endif
                </div>
                @if ($fige)
                    <p class="champ-aide">Lancer, suspendre et reprendre sont indisponibles tant que Hermes ne répond pas.</p>
                @elseif ($echec && ($t['dernier_statut'] ?? null) === 'delivery_failed')
                    <p class="champ-aide">À corriger côté Hermes : salon de livraison. La console ne modifie pas la configuration.</p>
                @endif
            </aside>
        </div>
        @endif
    </div>
    <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
</x-coque>
</div>
