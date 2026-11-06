{{-- Messagerie (DA-7, composants.md § 10 « pages sans maquette ») : titre-page → grille-sections : à gauche la liste
     des interlocuteurs (une .ligne : nom en b, pôle · modèle en small, « en train de répondre » en time) et, sous
     l'interlocuteur courant, ses conversations ; à droite le fil (une .ligne par message : auteur en b, texte, heure en
     time) puis la réponse en .formulaire (champ + bouton). Action à confirmer = pastille-action plein. Aucun verre. --}}
@php
    use App\Interface\Markdown;
    $statuts = ['en_cours' => 'en train de répondre', 'termine' => 'terminé', 'arrete' => 'arrêté', 'echec' => 'échec', 'interrompu' => 'interrompu', 'delai_depasse' => 'délai dépassé'];
    $enCours = (bool) ($fil['en_cours'] ?? false);
    $statutFil = $fil['conversation']['statut'] ?? null;
    $cible = $courant['nom'] ?? $interlocuteur;
@endphp
<div @if ($poll) wire:poll.{{ $poll }} @endif>
<x-coque actif="messagerie" :releve="$releve">
    <div @class(['fige' => $fige])>
        <div class="titre-page">
            <h1>Messagerie</h1>
            @if ($interlocuteur !== null)<span class="titre-page-sous">{{ $cible }}@if ($courant['modele'] ?? null) · {{ $courant['modele'] }}@endif</span>@endif
        </div>

        <div class="grille-sections">
            <section aria-labelledby="t-in" @class(['max-[900px]:hidden' => $interlocuteur !== null])>
                <h2 id="t-in">Interlocuteurs</h2>
                @forelse ($interlocuteurs as $i)
                    @php $actuel = $i['id'] === $interlocuteur; $convs = $conversations->where('interlocuteur', $i['id']); @endphp
                    <div class="ligne" wire:key="in-{{ $i['id'] }}">
                        <div class="ligne-texte"><b><a href="{{ route('messagerie.interlocuteur', $i['id']) }}" @if ($actuel) aria-current="true" @endif>{{ $i['nom'] }}</a></b><small>{{ $i['pole'] }} · {{ $i['modele'] }}</small></div>
                        @if ($i['en_cours'] ?? false)<time>en train de répondre</time>@endif
                    </div>
                    @if ($actuel)
                        <ul class="liste" aria-label="Conversations de {{ $i['nom'] }}">
                            @foreach ($convs as $c)
                                <li class="ligne" wire:key="conv-{{ $c['id'] }}">
                                    <div class="ligne-texte"><b><a href="{{ route('messagerie.conversation', [$i['id'], $c['id']]) }}" @if ($c['id'] === $idFil) aria-current="true" @endif>{{ $c['titre'] }}</a></b><small>{{ \Carbon\CarbonImmutable::parse($c['modifie_a'])->locale('fr')->isoFormat('D MMM') }}</small></div>
                                    <button type="button" class="bouton-texte" wire:click="archiver('{{ $c['id'] }}')" wire:confirm="Archiver cette conversation ?">Archiver</button>
                                </li>
                            @endforeach
                        </ul>
                        <div class="formulaire-actions"><a class="bouton bouton-fin" href="{{ route('messagerie.interlocuteur', ['interlocuteur' => $i['id'], 'nouvelle' => 1]) }}">Nouvelle conversation</a></div>
                    @endif
                @empty
                    <div class="etat-vide"><p>Aucun interlocuteur disponible.</p></div>
                @endforelse
            </section>

            @if ($interlocuteur !== null)
                <section aria-labelledby="t-fil">
                    <h2 id="t-fil">{{ $fil['conversation']['titre'] ?? 'Nouvelle conversation' }}@if ($statutFil)<span class="titre-page-sous">{{ $statuts[$statutFil] ?? $statutFil }}</span>@endif</h2>
                    <p class="min-[901px]:hidden"><a class="bouton-texte" href="{{ route('messagerie') }}">Tous les interlocuteurs</a></p>
                    @forelse ($blocs as $b)
                        <article class="ligne">
                            <div class="ligne-texte">
                                <b>{{ $b['auteur'] === 'utilisateur' ? 'Vous' : $cible }}</b>
                                @if ($b['auteur'] === 'utilisateur')
                                    <div class="md"><p>{!! nl2br(e($b['texte'])) !!}</p></div>
                                @else
                                    @if ($b['etapes'] !== [])
                                        <ol class="etapes" aria-label="Étapes">
                                            @foreach ($b['etapes'] as $e)<li><span>{{ $e }}</span></li>@endforeach
                                        </ol>
                                    @endif
                                    @if ($b['texte'] !== '')<div class="md">{{ Markdown::rendre($b['texte']) }}</div>@endif
                                    @if ($b['approbation'])
                                        <p role="status"><span class="pastille-action plein">Action à confirmer sur Discord ou au terminal</span></p>
                                        <small>{{ $cible }} demande une confirmation : elle ne se donne pas dans cette page. La réponse reprend dès que vous avez confirmé.</small>
                                    @endif
                                @endif
                            </div>
                            @if ($b['heure'] !== '')<time>{{ $b['heure'] }}</time>@endif
                        </article>
                    @empty
                        @if (! $enCours)<div class="etat-vide"><p>Aucun message. Écrire à {{ $cible }} ci-dessous.</p></div>@endif
                    @endforelse
                    @if ($enCours)
                        <article class="ligne" aria-live="polite">
                            <div class="ligne-texte">
                                <b>{{ $cible }}</b>
                                @if (! empty($fil['etapes_en_cours']))
                                    <ol class="etapes" aria-label="Étapes">@foreach ($fil['etapes_en_cours'] as $e)<li><span>{{ $e }}</span></li>@endforeach</ol>
                                @endif
                                @if (($fil['brouillon'] ?? '') !== '')<div class="md"><p>{{ $fil['brouillon'] }}</p></div>@endif
                            </div>
                            <time>en train de répondre</time>
                        </article>
                    @endif

                    <form class="formulaire" aria-label="Écrire à {{ $cible }}" wire:submit="envoyer" x-data="compositeur">
                        @if ($erreurMessagerie !== '')<p class="flash erreur" role="alert">{{ $erreurMessagerie }}</p>@endif
                        <div class="champ">
                            <label for="msg-texte">Message pour {{ $cible }}</label>
                            <textarea id="msg-texte" rows="3" wire:model="texte" maxlength="8000" @if ($prerempli) data-prerempli @endif x-on:keydown.enter="entree"></textarea>
                            <span class="champ-aide">Entrée = envoyer, Maj+Entrée = ligne</span>
                        </div>
                        <div class="formulaire-actions">
                            <button type="submit" class="bouton" @disabled($enCours)>Envoyer</button>
                            @if ($enCours)<button type="button" class="bouton-texte" wire:click="arreter">Arrêter</button>@endif
                        </div>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-coque>
</div>
