<?php

namespace App\Livewire;

use App\Hermes\HermesClient;
use App\Interface\Decisions;
use App\Interface\Temps;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Sessions (/consommation/sessions) : métadonnées seulement (titre, profil, source, durée, tokens), jamais la transcription.
 * « ?etat=en-cours » : les sessions actives, même règle que le compteur « en cours » de l'accueil ({@see Decisions::enCours}).
 */
#[Title('Sessions — hermes-os')]
class Sessions extends Page
{
    protected string $actif = 'consommation';

    #[Url(as: 'profil', except: '')]
    public string $profil = '';

    /** '' = toutes, 'en-cours' = sessions actives (lien du compteur « en cours » de l'accueil). */
    #[Url(as: 'etat', except: '')]
    public string $etat = '';

    public function render(): View
    {
        $sessions = $this->releve()->donnees(fn (HermesClient $c) => $c->sessions(100))['sessions'] ?? [];
        $profils = array_values(array_unique(array_column($sessions, 'profil')));
        sort($profils);
        if ($this->profil !== '') {
            $sessions = array_values(array_filter($sessions, fn (array $s) => $s['profil'] === $this->profil));
        }

        if ($this->etat === 'en-cours') {
            $sessions = Decisions::enCours($sessions, Temps::maintenant());
        }

        return $this->page('livewire.sessions', ['sessions' => $sessions, 'profils' => $profils]);
    }
}
