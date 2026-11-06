{{-- Accueil (DA-7 « Épure », maquettes/da-7-accueil.html, composants.md § 3-4) : bloc du jour en verre (légende,
     verdict, ligne d'état, 3 compteurs, frise 0-24 h + « maintenant »), puis grille À décider | Erreurs 24 h ;
     Prochaines | Tokens. Rien d'autre. --}}
@php
    use App\Interface\Decisions;
    use App\Interface\Libelles;
    use App\Interface\Nombres;
    use App\Interface\QuestionAgent;
    use App\Interface\Temps;
    use Illuminate\Support\Str;

    $nErr = count($jour->erreurs);
    $nDec = count($jour->decisions);
    $nCours = count($jour->enCours);
    $arrete = $jour->gatewayArrete();
    [$libGw] = Libelles::gateway($jour->gateway['etat'] ?? null);
    $frise = $jour->frise;

    // Verdict (h1) : jamais « Tout tourne » sur des données figées.
    $parties = array_values(array_filter([
        $nErr ? Libelles::insecables(Nombres::accord($nErr, 'erreur', 'erreurs').' depuis 24 h') : null,
        $nDec ? Nombres::accord($nDec, 'décision', 'décisions') : null,
    ]));
    $echecsLivraison = array_sum(array_column($jour->plateformes, 'echecs'));
    $tk = $jour->tokens;
    $echelle = max(1, (float) ($tk['echelle']['echelle'] ?? 1));
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="accueil" :releve="$releve" accueil>
    <x-resultat :resultat="$resultat" :attente="$enAttente !== null" />

    <section class="jour verre" aria-label="Le jour en cours">
        <div class="jour-legende">Situation au {{ lcfirst(Temps::etiquette()) }}</div>
        <h1>
            @if ($fige)
                <span>État inconnu : Hermes ne répond pas</span>
            @elseif ($jour->gateway !== [] && ! $jour->gatewayEnMarche())
                <span class="rouge">{{ $arrete ? 'Gateway arrêté' : 'Gateway '.Str::lower($libGw) }}</span>
            @elseif ($parties === [])
                <span>Tout tourne.</span>
            @else
                @foreach ($parties as $p)<span>{{ $p }}</span>@if (! $loop->last) <span class="separateur" aria-hidden="true">·</span> @endif @endforeach
            @endif
        </h1>
        {{-- Seule annonce de l'accueil : texte inchangé tant que les chiffres ne changent pas. --}}
        <span class="sr-only" role="status">{{ $fige ? 'Hermes ne répond pas, données figées' : Libelles::insecables(Nombres::accord($nErr, 'erreur', 'erreurs').' en 24 h').' · '.Nombres::accord($nDec, 'décision attendue', 'décisions attendues') }}</span>

        <p class="jour-etat">
            @if ($fige && $releve->donneesDe())Dernier état connu à {{ Temps::heure($releve->donneesDe()) }} · @endif
            @if ($jour->gateway === [])Gateway : état inconnu
            @elseif ($arrete)<span class="rouge">Gateway arrêté à {{ Temps::heure($jour->gateway['mis_a_jour_a'] ?? null) }}</span>
            @else Gateway {{ Str::lower($libGw) }}
            @endif
            @if ($jour->plateformes !== []) · {{ Nombres::accord(count($jour->plateformes), 'canal connecté', 'canaux connectés') }}@endif
            @if ($echecsLivraison > 0) · <a href="#er">{{ Libelles::insecables(Nombres::accord($echecsLivraison, 'livraison échouée', 'livraisons échouées')) }}</a>@endif
        </p>

        <div class="compteurs">
            <div><b @class(['rouge' => $nErr > 0])>{{ $nErr }}</b><span>{{ Libelles::insecables('erreurs 24 h') }}</span></div>
            <div><b>{{ $nDec }}</b><span>à décider</span></div>
            {{-- Pas de section « En cours » dans la maquette DA-7 : le compteur mène à la liste des sessions actives. --}}
            <div><a class="compteur-lien" href="{{ route('consommation.sessions', ['etat' => 'en-cours']) }}"><b>{{ $nCours }}</b><span>en cours</span></a></div>
        </div>

        <div class="frise" role="img" aria-label="Planifiées sur 0-24&nbsp;h : {{ Nombres::accord($frise->reussies, 'réussie', 'réussies') }}, {{ Nombres::accord($frise->echecs, 'en échec', 'en échec') }}, {{ Nombres::accord($frise->aVenir, 'à venir', 'à venir') }}">
            <div class="frise-ligne"></div>
            @foreach ($frise->passes as $p)
                <span @class(['frise-point', 'echec' => $p['echec'], 'frise-t-'.max(0, min(1440, (int) $p['minute']))])></span>
            @endforeach
            @foreach ($frise->venir as $p)
                <span class="frise-point prevu frise-t-{{ max(0, min(1440, (int) $p['minute'])) }}"></span>
            @endforeach
            <span class="frise-maintenant frise-t-{{ max(0, min(1440, $frise->minuteMaintenant)) }}{{ $frise->minuteMaintenant > 1224 ? ' fin' : '' }}"><i>maintenant {{ sprintf('%02d:%02d', intdiv($frise->minuteMaintenant, 60), $frise->minuteMaintenant % 60) }}</i></span>
        </div>
        <div class="frise-heures" aria-hidden="true"><span>00&nbsp;h</span><span>06&nbsp;h</span><span>12&nbsp;h</span><span>18&nbsp;h</span><span>24&nbsp;h</span></div>
    </section>

    <div class="grille-sections">
        <section id="ad" class="s-decider" aria-labelledby="t-ad">
            <h2 id="t-ad">À décider</h2>
            @forelse (array_slice($jour->decisions, 0, 3) as $c)
                @php $q = QuestionAgent::depuis($c['texte']); @endphp
                <div class="ligne" wire:key="ad-{{ $c['conversation'] }}">
                    <div class="ligne-texte"><b title="{{ $c['texte'] }}">{{ Str::limit($q['question'] ?? $q['contexte'], 140) }}</b><small>{{ $c['nom'] }} · <x-temps :t="$c['cree_a']" :fige="$fige" /></small></div>
                    <a @class(['pastille-action', 'plein' => $loop->first]) href="{{ Decisions::lien($c) }}">{{ Decisions::motif($c) === 'Question' ? 'Répondre' : 'Examiner' }}</a>
                </div>
            @empty
                <div class="etat-vide"><p>Rien n'attend de décision.</p></div>
            @endforelse
        </section>

        <section id="er" class="s-erreurs" aria-labelledby="t-er">
            <h2 id="t-er">Erreurs 24&nbsp;h</h2>
            @forelse ($jour->erreurs as $i => $e)
                <div class="ligne" wire:key="er-{{ $i }}-{{ md5($e['titre']) }}">
                    <div class="ligne-texte"><b>@if (! empty($e['lien']))<a href="{{ $e['lien'] }}">{{ $e['titre'] }}</a>@else{{ $e['titre'] }}@endif</b><small class="rouge" title="{{ $e['explication'] }}">{{ ($e['nombre'] > 1 ? $e['nombre'].'× ' : '').$e['motif'] }}</small></div>
                    <time datetime="{{ Temps::iso($e['quand']) }}" title="{{ Temps::complet($e['quand']) }}">{{ Temps::heure($e['quand']) }}</time>
                </div>
            @empty
                <div class="etat-vide"><p>Aucune erreur depuis 24&nbsp;h.</p></div>
            @endforelse
        </section>

        <section class="s-prochaines" aria-labelledby="t-pp">
            <h2 id="t-pp">Prochaines</h2>
            @if ($arrete)
                <div class="etat-vide"><p>Suspendues tant que le gateway est arrêté.</p></div>
            @endif
            @forelse ($jour->prochaines as $t)
                <div class="ligne ligne-prochaine" wire:key="pp-{{ $t['id'] }}">
                    <span class="ligne-heure">{{ Temps::heure($t['prochaine_execution']) }}</span>
                    <div class="ligne-texte"><b><a href="{{ route('planifiees.fiche', $t['id']) }}">{{ $t['nom'] }}</a></b></div>
                    <time datetime="{{ Temps::iso($t['prochaine_execution']) }}" title="{{ Temps::complet($t['prochaine_execution']) }}">{{ $arrete ? 'suspendue' : ($fige ? Temps::jour($t['prochaine_execution']) : (Temps::jourRelatif($t['prochaine_execution']) === 'aujourd’hui' ? Temps::relatif($t['prochaine_execution']) : (Temps::jourRelatif($t['prochaine_execution']) ?? Temps::jour($t['prochaine_execution'])))) }}</time>
                </div>
            @empty
                <div class="etat-vide"><p>Aucune exécution prévue.</p></div>
            @endforelse
        </section>

        <section class="s-tokens" aria-labelledby="t-tk">
            <h2 id="t-tk">Tokens</h2>
            @if (($tk['jours'] ?? []) === [])
                <div class="etat-vide"><p>Aucune session enregistrée sur 7 jours.</p></div>
            @else
                <div class="chiffre-grand"><b>{{ Nombres::tokens($tk['jour']['hors_cache'] ?? 0, true) }}</b><span>aujourd’hui, hors cache</span></div>
                <div class="histo" role="img" aria-label="Tokens hors cache, 7 derniers jours">
                    @foreach ($tk['jours'] as $j)
                        <div><i class="histo-h-{{ (int) round(min(100, max(0, $j['hors_cache'] / $echelle * 100))) }}" title="{{ Temps::date($j['date'].' 12:00') }} · {{ Nombres::tokens($j['hors_cache'], true) }}{{ $j['hors_cache'] > $echelle ? ' (hors échelle)' : '' }}"></i></div>
                    @endforeach
                </div>
                <div class="histo-jours" aria-hidden="true">@foreach ($tk['jours'] as $j)<span>{{ substr($j['date'], 8, 2) }}</span>@endforeach</div>
                @if ($tk['jour'])
                    <p class="histo-detail">{{ Nombres::accord($tk['jour']['sessions'] ?? 0, 'session', 'sessions') }} · <a href="{{ route('consommation') }}">Consommation</a></p>
                @endif
            @endif
        </section>
    </div>

    <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
</x-coque>
</div>
