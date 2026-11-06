{{-- Compte (DA-7, maquettes/da-7-compte.html, composants.md § 9) : à gauche « Mon compte » (fiche à filets), sécurité,
     appareils de confiance, comptes ; à droite le panneau en verre « Hermes relié » (état réel du pont) et le journal.
     Uniquement ce qui fonctionne : aucune donnée fictive des maquettes. --}}
@php
    use App\Interface\Presence;
    use App\Interface\Temps;

    $pontOk = ! $releve->fige() && ($sante['ok'] ?? false);
    $client = config('hermes-os.client');
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="compte" :releve="$releve">
    <x-resultat :resultat="$resultat" :attente="$enAttente !== null" />
    <div class="titre-page"><h1>Compte</h1><a href="#hermes-relie" class="titre-page-sous min-[1101px]:hidden">Hermes relié</a></div>

    <div class="duo">
        <div>
            <section class="section" aria-labelledby="t-mc">
                <h2 id="t-mc" class="section-titre"><span>Mon compte</span></h2>
                <div class="fiche-ligne"><span>Nom</span><b>{{ $user->name }}</b><span></span></div>
                <div class="fiche-ligne"><span>Identifiant</span><b class="break-all">{{ $user->email }}</b><span></span></div>
                <div class="fiche-ligne"><span>Mot de passe</span><span>••••••••</span><a href="{{ route('mot-de-passe.changer') }}">Changer</a></div>
                <div class="fiche-ligne"><span>Double authentification</span><span><span class="ok">Activée</span>@if ($user->two_factor_confirmed_at) <small>depuis le {{ Temps::dateLongue($user->two_factor_confirmed_at) }}</small>@endif</span><span></span></div>
                <div class="fiche-ligne"><span>Codes de secours</span><span>{{ $codes === 0 ? 'aucun' : \App\Interface\Nombres::accord($codes, 'restant', 'restants') }}</span><button type="button" id="btn-codes" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderNouveauxCodes">Régénérer…</button></div>
                <div class="fiche-ligne"><span>Code TOTP</span><span>@if ($sansCodeJusqua)Actions autorisées sans code jusqu'à <b>{{ Temps::heure($sansCodeJusqua) }}</b>@else Prochaine action : code TOTP demandé.@endif</span><span></span></div>
                {{-- Une seule entrée par session : « Cet appareil » porte « Se déconnecter » (plus de ligne « Session » en double). --}}
                @foreach ($sessions as $s)
                    <div class="fiche-ligne" wire:key="s-{{ md5($s['id']) }}"><span>@if ($loop->first)Sessions ouvertes @endif</span><span>{{ $s['courante'] ? 'Cet appareil' : $s['agent'] }}<br><small>@if ($s['courante']){{ $s['agent'] }} · maintenant @else{{ $s['ip'] ?? 'IP inconnue' }} · <x-temps :t="$s['activite']" />@endif</small></span>@if ($s['courante'])<form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="bouton-texte">Se déconnecter</button></form>@else<button type="button" id="btn-session-{{ $loop->index }}" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderFermetureSession('{{ $s['id'] }}')">Fermer</button>@endif</div>
                @endforeach
                @if (count($sessions) > 2)
                    <div class="fiche-ligne"><span></span><span>{{ count($sessions) - 1 }} autres sessions</span><button type="button" id="btn-autres" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderDeconnexionAutres">Tout fermer…</button></div>
                @endif
            </section>

            {{-- Appareils de confiance (ADR 0003, amendement) : connexion sans code si appareil ET réseau de confiance. --}}
            <section class="section" aria-labelledby="t-cf">
                <h2 id="t-cf" class="section-titre"><span>Appareils de confiance</span></h2>
                <p class="champ-aide">Depuis un réseau déclaré ici, un appareil de confiance se connecte avec le mot de passe seul, pendant {{ config('hermes-os.auth.appareil.jours_confiance') }} jours. Les actions demandent toujours le code.</p>
                <div class="fiche-ligne"><span>Réseau actuel</span><span>{{ $confiance['ip'] ?? '—' }} <small>· {{ $confiance['ipDeConfiance'] ? 'de confiance' : 'non déclaré' }}</small></span><span></span></div>
                @forelse ($confiance['reseaux'] as $r)
                    <div class="fiche-ligne" wire:key="r-{{ $r->id }}"><span>Réseau</span><span><b>{{ $r->libelle }}</b> <small>{{ $r->plage }}</small></span><button type="button" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderRetraitReseau({{ $r->id }})">Retirer…</button></div>
                @empty
                    <div class="etat-vide"><p>Aucun réseau : le code est demandé à chaque connexion.</p></div>
                @endforelse
                @unless ($confiance['ipDeConfiance'])
                    <form class="formulaire" wire:submit="demanderAjoutReseau">
                        <div @class(['champ', 'erreur' => $errors->has('libelleReseau')])>
                            <label for="c-reseau">Nom du réseau actuel</label>
                            <input id="c-reseau" wire:model="libelleReseau" maxlength="60" required placeholder="Maison, Boulot…" @error('libelleReseau') aria-invalid="true" aria-describedby="e-reseau" @enderror>
                            @error('libelleReseau')<span id="e-reseau" class="champ-erreur" role="alert">{{ $message }}</span>@enderror
                        </div>
                        <div class="formulaire-actions"><x-bouton type="submit" variante="secondaire" x-data="declencheur" x-on:click="memoriser">Ajouter ce réseau…</x-bouton></div>
                    </form>
                @endunless
                @forelse ($confiance['appareils'] as ['appareil' => $a, 'courant' => $courant])
                    <div class="fiche-ligne" wire:key="a-{{ $a->id }}"><span>Appareil</span><span><b>{{ $a->libelle }}</b>@if ($courant) · cet appareil @endif <small>jusqu’au {{ Temps::date($a->expire_le) }}@if ($a->utilise_le) · utilisé <x-temps :t="$a->utilise_le" />@endif</small></span><button type="button" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderRevocationAppareil({{ $a->id }})">Révoquer…</button></div>
                @empty
                    <div class="etat-vide"><p>Aucun appareil de confiance.</p></div>
                @endforelse
                @if (($confiance['ipDeConfiance'] && ! $confiance['appareilCourant']) || count($confiance['appareils']) > 1)
                    <div class="formulaire-actions">
                        @if ($confiance['ipDeConfiance'] && ! $confiance['appareilCourant'])
                            <x-bouton id="btn-confiance" x-data="declencheur" x-on:click="memoriser" wire:click="demanderConfianceAppareil">Faire confiance à cet appareil…</x-bouton>
                        @endif
                        @if (count($confiance['appareils']) > 1)
                            <x-bouton variante="secondaire" x-data="declencheur" x-on:click="memoriser" wire:click="demanderRevocationAppareils">Tout révoquer…</x-bouton>
                        @endif
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="t-co" x-data="depliable" data-ouvert="{{ $errors->hasAny(['nouveauNom', 'nouvelEmail']) ? '1' : '0' }}">
                <h2 id="t-co" class="section-titre"><span>Comptes</span><button type="button" id="btn-nouveau-compte" class="bouton bouton-fin" x-on:click="basculer" aria-controls="f-compte">Nouveau compte</button></h2>
                <form id="f-compte" class="formulaire" wire:submit="demanderAjoutCompte" x-show="ouvert" x-cloak>
                    <div @class(['champ', 'erreur' => $errors->has('nouveauNom')])>
                        <label for="c-nom">Nom</label>
                        <input id="c-nom" wire:model="nouveauNom" maxlength="255" required autocomplete="off" @error('nouveauNom') aria-invalid="true" aria-describedby="e-nom" @enderror>
                        @error('nouveauNom')<span id="e-nom" class="champ-erreur" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <div @class(['champ', 'erreur' => $errors->has('nouvelEmail')])>
                        <label for="c-email">E-mail</label>
                        <input id="c-email" type="email" wire:model="nouvelEmail" maxlength="255" required autocomplete="off" @error('nouvelEmail') aria-invalid="true" aria-describedby="e-email" @enderror>
                        @error('nouvelEmail')<span id="e-email" class="champ-erreur" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <p class="champ-aide">Un mot de passe provisoire de {{ \App\Livewire\Compte::LONGUEUR_PROVISOIRE }} caractères est généré et affiché une seule fois.</p>
                    <div class="formulaire-actions"><x-bouton type="submit" id="btn-ajout-compte" x-data="declencheur" x-on:click="memoriser">Créer le compte…</x-bouton></div>
                </form>
                {{-- dc M1 L3 : la confirmation d'ajout s'affiche ici, entre le formulaire et le mot de passe provisoire. --}}
                @if (($confirmation['action'] ?? null) === 'comptes.ajouter')
                    <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
                @endif
                {{-- Mot de passe provisoire (M1 secu, M1/M3 design-critic) : reçu une seule fois par événement navigateur,
                     jamais rendu par le serveur ni gardé dans l'état Livewire ; « Masquer » l'efface définitivement. --}}
                <div id="provisoire" class="provisoire" x-data="provisoire" x-on:mdp-provisoire.window="recevoir" x-show="visible" x-cloak wire:ignore tabindex="-1" role="status">
                    <p class="provisoire-titre" x-text="message"></p>
                    <div class="commande provisoire-secret" aria-label="Mot de passe provisoire"><template x-for="g in groupes"><span x-text="g"></span></template></div>
                    <div class="provisoire-actions"><button type="button" class="bouton-texte" x-on:click="copier" x-text="libelle">Copier</button><button type="button" class="bouton-texte" x-on:click="masquer">Masquer</button></div>
                    <p class="champ-aide" x-text="aide"></p>
                </div>
                @foreach ($comptes as $c)
                    <div @class(['compte', 'desactive' => ! $c->estActif()]) wire:key="c-{{ $c->id }}">
                        <div class="compte-qui"><span @class(['avatar', 'connecte' => $c->estConnecte()]) aria-hidden="true">{{ Presence::initialesDe($c) }}</span><div><b>{{ $c->name }}@if ($c->is($user)) · vous @endif</b><small>{{ $c->email }}</small></div></div>
                        <div class="compte-etat">
                            @if (! $c->estActif())
                                <x-temps :t="$c->desactive_le" format="longue" avant="désactivé le " />
                            @elseif ($c->estConnecte())
                                <time class="connecte">connecté</time>
                            @elseif ($c->derniere_activite_le)
                                <x-temps :t="$c->derniere_activite_le" avant="dernière connexion " />
                            @else
                                <time>jamais connecté</time>
                            @endif
                            {{-- dc m3 L3 : colonne d'action de largeur fixe, même vide (ligne « vous »). --}}
                            <span class="compte-action">
                            @unless ($c->is($user))
                                @if ($c->estActif())
                                    <button type="button" id="btn-desactiver-{{ $c->id }}" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderDesactivation({{ $c->id }})">Désactiver</button>
                                @else
                                    <button type="button" id="btn-reactiver-{{ $c->id }}" class="bouton-texte" x-data="declencheur" x-on:click="memoriser" wire:click="demanderReactivation({{ $c->id }})">Réactiver</button>
                                @endif
                            @endunless
                            </span>
                        </div>
                    </div>
                @endforeach
            </section>
        </div>

        <aside id="hermes-relie" class="panneau-relie verre" aria-labelledby="t-hr">
            <h2 id="t-hr">Hermes relié</h2>
            @php
                $confPont = $confirmation['action'] ?? null;
                // Hors ligne : les dernières valeurs connues restent lisibles mais grisées, rien n'est donné pour vérifié (design-critic M1).
                $fige = ! $pontOk;
            @endphp
            @if ($distant)
                {{-- Pilote distant (L4) : instance reliée par HTTPS sortant, relevés poussés toutes les 15 s. --}}
                @if ($instance)
                    <div class="instance">
                        <b>{{ $instance->nom }}</b>
                        <span>{{ collect([$instance->hote, $instance->version])->filter()->implode(' · ') }}</span>
                        <span>@if ($pontOk)<span class="ok">En ligne</span>@else<span class="hors-ligne">Hors ligne</span>@endif<span @class(['instance-mesures', 'fige' => $fige])>@if ($instance->latence_ms !== null) · {{ $instance->latence_ms }} ms @endif · {{ count($instance->profils ?? []) }} profil{{ count($instance->profils ?? []) > 1 ? 's' : '' }}</span>@if ($instance->dernier_releve_le) · @if ($fige && $instance->dernier_releve_le?->lt(now()->subDay()))<x-temps :t="$instance->dernier_releve_le" format="absolu" avant="dernier relevé " />@elseif ($fige)<x-temps :t="$instance->dernier_releve_le" format="heure" avant="dernier relevé à " />@else<x-temps :t="$instance->dernier_releve_le" avant="relevé " />@endif @endif</span>
                    </div>
                    {{-- Confirmation rendue à la place du bouton qui l'ouvre (design-critic M2). --}}
                    @if ($confPont === 'pont.delier')
                        <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" classe="etat-vide confirmation-panneau" />
                    @else
                        <p><button type="button" id="btn-delier" class="bouton-danger" x-data="declencheur" x-on:click="memoriser" wire:click="demanderDeliaison">Délier</button></p>
                    @endif
                @else
                    <div class="instance"><b>Aucun Hermes relié</b><span><span class="hors-ligne">Hors ligne</span></span></div>
                @endif
                <h3>{{ $instance ? 'Relier un autre Hermes' : 'Relier un Hermes' }}</h3>
                <ol>
                    <li>Sur la machine du Hermes à relier, lancez la commande.</li>
                    <li>Saisissez le code quand le pont le demande (générez-le ci-dessous).</li>
                    <li>La console affiche l’instance dès le premier relevé.</li>
                </ol>
                @php $commande = 'curl -fsSL '.rtrim(config('app.url'), '/').'/pont/installer.sh | sh'; @endphp
                {{-- Retour à la ligne avant « | » ; l'URL passe à la ligne si elle est trop longue, lisible entière (dc m1, m6 L4). --}}
                <div class="commande commande-liaison">{{ 'curl -fsSL '.rtrim(config('app.url'), '/').'/pont/installer.sh' }}
  | sh</div>
                <p class="commande-copie"><button type="button" class="bouton-texte" x-data="copie" data-texte="{{ $commande }}" x-on:click="copier" x-text="libelle">Copier la commande</button></p>
                {{-- Code reçu par l'événement « code-appairage » (jamais rendu par le serveur ni gardé dans l'état Livewire). --}}
                <div x-data="codeAppairage" data-fuseau="{{ \App\Interface\Temps::fuseau() }}" x-on:code-appairage.window="recevoir" x-on:code-appairage-efface.window="effacer">
                    <div wire:ignore>
                        <div class="code-appairage" x-ref="code" x-show="code" x-cloak role="status">
                            <b x-text="code"></b>
                            <span><span x-text="reste"></span> <button type="button" id="btn-copier-code" class="bouton-texte" data-focus-repli x-on:click="copier" x-text="libelle">Copier</button></span>
                        </div>
                        <div class="code-appairage code-expire" x-ref="expire" x-show="expire" x-cloak role="status"><span x-text="expire"></span></div>
                    </div>
                    @if ($confPont === 'pont.code')
                        <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" classe="etat-vide confirmation-panneau" />
                    @else
                        <p class="generer-code" x-show="!code"><button type="button" id="btn-generer" class="bouton bouton-fin" data-focus-repli x-data="declencheur" x-on:click="memoriser" wire:click="demanderCodeAppairage">Générer un code</button></p>
                    @endif
                </div>
            @else
            <div class="instance">
                <b>{{ filled($client) ? $client : 'Instance Hermes' }}</b>
                <span>@if ($pontOk)<span class="ok">En ligne</span>@else<span class="hors-ligne">Hors ligne</span>@endif @if (! empty($sante['version_pont'])) · pont {{ $sante['version_pont'] }}@endif</span>
            </div>
            @endif
            @if (($sante['verifications'] ?? []) !== [])
                <h3>Vérifications</h3>
                @foreach ($sante['verifications'] as $v)
                    @if ($fige)
                        {{-- dc m7 L4 : figé, le détail (« actif », « lisible ») contredirait « Non vérifié » : il n'est pas affiché. --}}
                        <div class="fiche-ligne fige"><span>{{ $v['nom'] }}</span><span>Non vérifié</span></div>
                    @else
                        <div class="fiche-ligne"><span>{{ $v['nom'] }}</span><span @class(['ok' => $v['ok'], 'rouge' => ! $v['ok']])>{{ $v['ok'] ? 'OK' : 'Erreur' }}</span><small>{{ $v['detail'] ?? '' }}</small></div>
                    @endif
                @endforeach
            @endif
            <h3 id="t-jo">Journal d’audit</h3>
            <p class="champ-aide">Connexions, codes et actions, en lecture seule.@if ($dernierEchec) Dernier échec : <b>{{ Temps::absolu($dernierEchec->created_at) }}</b>.@else Aucun échec enregistré.@endif</p>
            <p class="lien-journal"><a class="bouton-texte" href="{{ route('compte.journal') }}">Ouvrir le journal</a></p>
        </aside>
    </div>

    @unless (str_starts_with($confirmation['action'] ?? '', 'pont.') || ($confirmation['action'] ?? null) === 'comptes.ajouter')
        <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
    @endunless
</x-coque>
</div>
