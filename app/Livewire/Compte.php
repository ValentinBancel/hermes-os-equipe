<?php

namespace App\Livewire;

use App\Audit\Journal;
use App\Auth\AppareilsDeConfiance;
use App\Hermes\HermesClient;
use App\Interface\Navigateur;
use App\Models\AppareilDeConfiance;
use App\Models\AuditLog;
use App\Models\PontInstance;
use App\Models\ReseauDeConfiance;
use App\Models\User;
use App\Pont\Appairage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

/**
 * Compte (ux/03 § Compte, m11 ; L3 multi-comptes) : mon compte (sessions, double authentification), comptes de la
 * console (ajout, désactivation, réactivation), confiance, accès au journal.
 * Les actions qui touchent la base de l'app (fermer une session, ajouter/désactiver un compte, codes de secours…)
 * passent par la même confirmation + TOTP < 15 min + journal d'audit que les actions Hermes (ADR 0003).
 * Aucun rôle (L3, C1) : tout compte actif a tous les droits, y compris sur les autres comptes.
 */
#[Title('Compte — hermes-os')]
class Compte extends Page
{
    protected string $actif = 'compte';

    /** Nom du réseau à ajouter (« Maison », « Boulot ») ; l'IP vient de la requête au moment de valider. */
    public string $libelleReseau = '';

    /** Cible de la confirmation en cours (réseau ou appareil à retirer), fixée côté serveur. */
    #[Locked]
    public ?int $cible = null;

    /** Session à fermer (identifiant de la table `sessions`), fixée côté serveur après contrôle d'appartenance. */
    #[Locked]
    public ?string $cibleSession = null;

    /** Formulaire « Ajouter un compte ». */
    public string $nouveauNom = '';

    public string $nouvelEmail = '';

    /**
     * Compte à créer, figé côté serveur au moment de la confirmation (le formulaire reste modifiable par le navigateur).
     *
     * @var array{nom: string, email: string}|null
     */
    #[Locked]
    public ?array $compteAAjouter = null;

    /** Longueur du mot de passe provisoire (L3, C4). */
    public const LONGUEUR_PROVISOIRE = 20;

    /**
     * Sessions de ce compte. Lecture directe de la table `sessions` (driver `database`, ADR 0003) : Laravel n'a pas
     * de modèle Eloquent pour elle ; requête bornée à l'utilisateur courant.
     *
     * @return list<array{id: string, ip: ?string, agent: string, activite: int, courante: bool}>
     */
    private function sessions(User $user): array
    {
        // Cet appareil figure toujours dans la liste (il porte « Se déconnecter »), même si sa ligne n'est pas encore écrite.
        $courante = [
            'id' => session()->getId(), 'ip' => request()->ip(), 'agent' => $this->agent((string) request()->userAgent()),
            'activite' => now()->getTimestamp(), 'courante' => true,
        ];
        if (config('session.driver') !== 'database') {
            return [$courante];
        }

        $sessions = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $s) => [
                'id' => (string) $s->id,
                'ip' => $s->ip_address,
                'agent' => $this->agent((string) $s->user_agent),
                'activite' => (int) $s->last_activity,
                'courante' => $s->id === session()->getId(),
            ])->all();

        return in_array(true, array_column($sessions, 'courante'), true) ? $sessions : [$courante, ...$sessions];
    }

    private function agent(string $ua): string
    {
        return Navigateur::libelle($ua);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();
        $codes = $user->two_factor_recovery_codes ? count($user->recoveryCodes()) : 0;
        $confirme = session('hermes.totp_confirme_a');
        $minutes = (int) config('hermes-os.auth.minutes_validite_totp');

        return $this->page('livewire.compte', [
            'user' => $user,
            'sessions' => $this->sessions($user),
            'codes' => $codes,
            'sansCodeJusqua' => is_int($confirme) && $confirme + $minutes * 60 > now()->getTimestamp() ? $confirme + $minutes * 60 : null,
            'confiance' => $this->confiance($user),
            'dernierEchec' => AuditLog::query()->whereIn('event', ['connexion.echec', 'totp.echec'])->latest('id')->first(),
            'comptes' => User::query()->orderByRaw('desactive_le is not null')->orderBy('name')
                ->get(['id', 'name', 'email', 'desactive_le', 'derniere_activite_le']),
            'sante' => $this->releve()->donnees(fn (HermesClient $c) => $c->sante()),
            'distant' => config('hermes-os.pont.driver') === 'distant',
            'instance' => config('hermes-os.pont.driver') === 'distant' ? PontInstance::active() : null,
        ]);
    }

    public function demanderCodeAppairage(): void
    {
        // La confirmation nomme l'instance remplacée (design-critic M2) ; pas d'avertissement rouge : rien n'est en erreur.
        $active = PontInstance::active();
        if ($active !== null) {
            $this->ouvrirConfirmationLocale('pont.code', "Remplacer {$active->nom} ?",
                "Un code d’appairage valable 10 minutes est généré ; {$active->nom} sera remplacé dès l’échange du code. Le code précédent est annulé.",
                'générer');

            return;
        }
        $this->ouvrirConfirmationLocale('pont.code', 'Générer un code d’appairage ?',
            'Valable 10 minutes. Le code précédent est annulé.', 'générer');
    }

    public function demanderDeliaison(): void
    {
        $active = PontInstance::active();
        if ($active === null) {
            $this->terminer(true, 'Aucun Hermes relié.');

            return;
        }
        $this->ouvrirConfirmationLocale('pont.delier', "Délier {$active->nom} ?",
            'Son accès est révoqué ; son pont s’arrête à la prochaine requête.', 'délier', danger: true);
    }

    public function demanderDeconnexionAutres(): void
    {
        $autres = count(array_filter($this->sessions(auth()->user()), fn (array $s) => ! $s['courante']));
        if ($autres === 0) {
            $this->terminer(true, 'Aucun autre appareil connecté.');

            return;
        }
        $this->ouvrirConfirmationLocale('compte.deconnecter_autres', 'Déconnecter les autres appareils',
            "Fermer {$autres} autre(s) session(s) ? Cet appareil reste connecté.", 'déconnecter');
    }

    public function demanderFermetureSession(string $id): void
    {
        $session = collect($this->sessions(auth()->user()))->firstWhere('id', $id);
        if ($session === null) {
            $this->terminer(true, 'Session déjà fermée.');

            return;
        }
        if ($session['courante']) {
            $this->terminer(false, 'C’est la session de cet appareil : utiliser « Se déconnecter ».');

            return;
        }
        $this->cibleSession = $session['id'];
        $this->ouvrirConfirmationLocale('compte.session_fermer', 'Fermer une session',
            "Fermer la session « {$session['agent']} »".($session['ip'] ? " ({$session['ip']})" : '').' ? Cet appareil devra se reconnecter.', 'fermer');
    }

    public function demanderAjoutCompte(): void
    {
        $this->nouvelEmail = mb_strtolower(trim($this->nouvelEmail));
        $this->nouveauNom = trim($this->nouveauNom);
        $this->validate([
            'nouveauNom' => ['required', 'string', 'max:255'],
            'nouvelEmail' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
        ], ['nouvelEmail.unique' => 'Un compte utilise déjà cette adresse.'], ['nouveauNom' => 'nom', 'nouvelEmail' => 'e-mail']);
        $this->compteAAjouter = ['nom' => $this->nouveauNom, 'email' => $this->nouvelEmail];
        $this->ouvrirConfirmationLocale('comptes.ajouter', 'Créer le compte',
            "Créer le compte de {$this->nouveauNom} ({$this->nouvelEmail}) ? Il aura tous les droits sur la console.", 'créer le compte',
            'Le mot de passe provisoire s’affiche une seule fois : le transmettre par un canal sûr. Il devra être changé à la première connexion, puis la double authentification activée.');
    }

    public function demanderDesactivation(int $id): void
    {
        $compte = User::query()->findOrFail($id);
        if ($refus = $this->refusDesactivation($compte)) {
            $this->terminer(false, $refus);

            return;
        }
        $this->cible = $compte->getKey();
        $this->ouvrirConfirmationLocale('comptes.desactiver', 'Désactiver un compte',
            "Désactiver le compte de {$compte->name} ({$compte->email}) ? Ses sessions sont fermées et ses appareils de confiance révoqués ; il ne pourra plus se connecter.", 'désactiver');
    }

    public function demanderReactivation(int $id): void
    {
        $compte = User::query()->findOrFail($id);
        if ($compte->estActif()) {
            $this->terminer(true, 'Ce compte est déjà actif.');

            return;
        }
        $this->cible = $compte->getKey();
        $this->ouvrirConfirmationLocale('comptes.reactiver', 'Réactiver un compte',
            "Réactiver le compte de {$compte->name} ({$compte->email}) ? Il pourra de nouveau se connecter avec son mot de passe et son code.", 'réactiver');
    }

    /** Garde-fous de la désactivation (C5) : jamais soi-même, jamais le dernier compte actif. */
    private function refusDesactivation(User $compte): ?string
    {
        return match (true) {
            $compte->is(auth()->user()) => 'Impossible de désactiver son propre compte.',
            ! $compte->estActif() => 'Ce compte est déjà désactivé.',
            User::query()->actifs()->count() <= 1 => 'Impossible de désactiver le dernier compte actif.',
            default => null,
        };
    }

    public function demanderNouveauxCodes(): void
    {
        $this->ouvrirConfirmationLocale('compte.regenerer_codes', 'Régénérer les codes de secours',
            'Remplacer les codes de secours ? Les anciens ne fonctionneront plus ; les nouveaux s’affichent une seule fois.', 'régénérer',
            'Conserver les nouveaux codes hors de ce téléphone (gestionnaire de mots de passe).');
    }

    protected function actionLocale(string $cle, User $user): void
    {
        if ($this->actionConfiance($cle, $user) || $this->actionComptes($cle, $user)) {
            return;
        }

        if ($cle === 'pont.code') {
            $code = app(Appairage::class)->generer($user);
            // Le code part une seule fois dans la réponse, en événement navigateur (leçon L3 M1) : ni propriété
            // Livewire (absent du snapshot), ni rendu serveur, ni journal ; seule son empreinte est en base.
            $this->dispatch('code-appairage', code: Appairage::afficher($code['code']), expireA: $code['expire_le']->getTimestamp());
            $this->terminer(true, 'Code d’appairage généré : saisissez-le quand le pont le demande.');
            $this->resultat = null;

            return;
        }

        if ($cle === 'pont.delier') {
            app(Appairage::class)->delier($user);
            $this->dispatch('code-appairage-efface');
            $this->terminer(true, 'Hermes délié : son accès est révoqué.');

            return;
        }

        $journal = app(Journal::class);

        if ($cle === 'compte.deconnecter_autres') {
            $n = config('session.driver') === 'database'
                ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->where('id', '!=', session()->getId())->delete()
                : 0;
            $journal->ecrire('compte.deconnecter_autres', 'ok', $user, contexte: ['sessions' => $n]);
            $this->terminer(true, $n > 1 ? "{$n} sessions fermées." : ($n === 1 ? '1 session fermée.' : 'Aucune autre session.'));

            return;
        }

        if ($cle === 'compte.session_fermer') {
            $n = config('session.driver') === 'database' && $this->cibleSession !== session()->getId()
                ? DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->where('id', $this->cibleSession)->delete()
                : 0;
            $journal->ecrire('compte.session_fermee', 'ok', $user, contexte: ['sessions' => $n]);
            $this->cibleSession = null;
            $this->terminer(true, $n ? 'Session fermée.' : 'Session déjà fermée.');

            return;
        }

        if ($cle === 'compte.regenerer_codes') {
            app(GenerateNewRecoveryCodes::class)($user);
            $journal->ecrire('totp.codes_regeneres', 'ok', $user);
            session()->flash('hermes.afficher_codes', true);
            $this->redirectRoute('totp.codes');
        }
    }

    /**
     * Réseaux et appareils de confiance (ADR 0003, amendement), pour la section du même nom.
     *
     * @return array{ip: ?string, ipDeConfiance: bool, reseaux: list<ReseauDeConfiance>, appareils: list<array{appareil: AppareilDeConfiance, courant: bool}>, appareilCourant: bool}
     */
    private function confiance(User $user): array
    {
        $service = app(AppareilsDeConfiance::class);
        $courant = $service->depuisCookie(request(), $user);

        return [
            'ip' => request()->ip(),
            'ipDeConfiance' => $service->ipDeConfiance($user, request()->ip()),
            'reseaux' => $user->reseauxDeConfiance()->orderBy('libelle')->get()->all(),
            'appareils' => $user->appareilsDeConfiance()->where('expire_le', '>', now())->latest()->get()
                ->map(fn (AppareilDeConfiance $a) => ['appareil' => $a, 'courant' => $a->is($courant)])->all(),
            'appareilCourant' => $courant !== null,
        ];
    }

    public function demanderAjoutReseau(): void
    {
        $this->validate(['libelleReseau' => ['required', 'string', 'max:60']], [], ['libelleReseau' => 'nom du réseau']);
        $plage = app(AppareilsDeConfiance::class)->plagePour((string) request()->ip());
        if ($plage === null) {
            $this->terminer(false, 'Adresse IP illisible : réseau non ajouté.');

            return;
        }
        $this->ouvrirConfirmationLocale('compte.reseau_ajouter', 'Ajouter un réseau de confiance',
            "Déclarer « {$this->libelleReseau} » ({$plage}) comme réseau de confiance ? Depuis ce réseau, un appareil de confiance se connecte sans code.", 'ajouter',
            'À éviter sur un réseau public ou partagé (café, gare, hôtel).');
    }

    public function demanderRetraitReseau(int $id): void
    {
        $reseau = auth()->user()->reseauxDeConfiance()->findOrFail($id);
        $this->cible = $reseau->getKey();
        $this->ouvrirConfirmationLocale('compte.reseau_retirer', 'Retirer un réseau de confiance',
            "Retirer « {$reseau->libelle} » ({$reseau->plage}) ? Les sessions ouvertes sans code depuis ce réseau seront fermées.", 'retirer');
    }

    public function demanderConfianceAppareil(): void
    {
        $this->ouvrirConfirmationLocale('compte.appareil_ajouter', 'Faire confiance à cet appareil',
            'Ce navigateur se connectera sans code pendant '.config('hermes-os.auth.appareil.jours_confiance').' jours, uniquement depuis un réseau de confiance. Le mot de passe reste demandé, et le code aussi pour les actions.', 'faire confiance');
    }

    public function demanderRevocationAppareil(int $id): void
    {
        $appareil = auth()->user()->appareilsDeConfiance()->findOrFail($id);
        $this->cible = $appareil->getKey();
        $this->ouvrirConfirmationLocale('compte.appareil_revoquer', 'Révoquer un appareil',
            "Révoquer « {$appareil->libelle} » ? Il redemandera le code à la prochaine connexion, et sa session sans code est fermée.", 'révoquer');
    }

    public function demanderRevocationAppareils(): void
    {
        $this->cible = null;
        $this->ouvrirConfirmationLocale('compte.appareils_revoquer', 'Révoquer tous les appareils',
            'Révoquer tous les appareils de confiance ? Chacun redemandera le code à la prochaine connexion.', 'tout révoquer');
    }

    /** Actions de confiance (Compte) : appelées par actionLocale après confirmation et TOTP < 15 min. */
    private function actionConfiance(string $cle, User $user): bool
    {
        $journal = app(Journal::class);
        $service = app(AppareilsDeConfiance::class);

        switch ($cle) {
            case 'compte.reseau_ajouter':
                $plage = $service->plagePour((string) request()->ip());
                $libelle = mb_substr(trim($this->libelleReseau), 0, 60);
                if ($plage === null || $libelle === '') {
                    $this->terminer(false, 'Réseau non ajouté.');

                    return true;
                }
                $reseau = $user->reseauxDeConfiance()->updateOrCreate(['plage' => $plage], ['libelle' => $libelle]);
                $journal->ecrire('confiance.reseau_ajoute', 'ok', $user, contexte: ['reseau' => $reseau->getKey(), 'plage' => $plage]);
                $this->libelleReseau = '';
                $this->terminer(true, "Réseau « {$libelle} » ajouté.");

                return true;

            case 'compte.reseau_retirer':
                $reseau = $user->reseauxDeConfiance()->whereKey($this->cible)->first();
                $reseau?->delete();
                $journal->ecrire('confiance.reseau_retire', 'ok', $user, contexte: ['reseau' => $this->cible, 'plage' => $reseau?->plage]);
                $this->cible = null;
                $this->terminer(true, $reseau ? "Réseau « {$reseau->libelle} » retiré." : 'Réseau déjà retiré.');

                return true;

            case 'compte.appareil_ajouter':
                if (! $service->ipDeConfiance($user, request()->ip())) {
                    $this->terminer(false, 'Ce réseau n’est pas de confiance : ajouter d’abord le réseau.');

                    return true;
                }
                $service->depuisCookie(request(), $user)?->delete();
                $appareil = $service->creer($user, request(), Navigateur::libelle((string) request()->userAgent()));
                $journal->ecrire('appareil.confiance_ajoute', 'ok', $user, contexte: ['appareil' => $appareil->getKey(), 'source' => 'compte']);
                $this->terminer(true, 'Cet appareil est de confiance pour '.config('hermes-os.auth.appareil.jours_confiance').' jours.');

                return true;

            case 'compte.appareil_revoquer':
                $n = $user->appareilsDeConfiance()->whereKey($this->cible)->delete();
                $journal->ecrire('appareil.revoque', 'ok', $user, contexte: ['appareil' => $this->cible]);
                $this->cible = null;
                $this->terminer(true, $n ? 'Appareil révoqué.' : 'Appareil déjà révoqué.');

                return true;

            case 'compte.appareils_revoquer':
                $n = $service->revoquerTout($user);
                $journal->ecrire('appareil.revoque', 'ok', $user, contexte: ['tous' => true, 'nombre' => $n]);
                $this->terminer(true, $n > 1 ? "{$n} appareils révoqués." : ($n === 1 ? '1 appareil révoqué.' : 'Aucun appareil à révoquer.'));

                return true;
        }

        return false;
    }

    /** Actions sur les comptes (L3, C4–C5) : appelées par actionLocale après confirmation et TOTP < 15 min. */
    private function actionComptes(string $cle, User $user): bool
    {
        $journal = app(Journal::class);

        switch ($cle) {
            case 'comptes.ajouter':
                ['nom' => $nom, 'email' => $email] = $this->compteAAjouter ?? ['nom' => '', 'email' => ''];
                $this->compteAAjouter = null;
                if ($nom === '' || $email === '' || User::query()->where('email', $email)->exists()) {
                    $this->terminer(false, 'Compte non créé : nom ou e-mail invalide, ou adresse déjà utilisée.');

                    return true;
                }
                $motDePasse = Str::password(self::LONGUEUR_PROVISOIRE, symbols: false);
                $compte = new User(['name' => $nom, 'email' => $email, 'password' => $motDePasse]);
                $compte->forceFill(['mot_de_passe_provisoire' => true, 'cree_par' => $user->getKey()])->save();
                $journal->ecrire('compte.cree', 'ok', $user, contexte: ['source' => 'compte', 'cible' => $compte->getKey(), 'cible_email' => $email]);
                $this->nouveauNom = $this->nouvelEmail = '';
                // Le secret part une seule fois dans la réponse, en événement navigateur (M1 secu) : ni propriété
                // Livewire (absent du snapshot et des requêtes suivantes), ni rendu serveur, ni journal. La confirmation
                // « Compte de … créé. » s'affiche avec lui sous le formulaire, sans bannière en haut de page.
                $this->dispatch('mdp-provisoire', nom: $nom, email: $email, motDePasse: $motDePasse);
                $this->terminer(true, "Compte de {$nom} créé.");
                $this->resultat = null;

                return true;

            case 'comptes.desactiver':
                $cible = $this->cible;
                $this->cible = null;
                // Garde + écriture sous verrou (M2 secu) : deux comptes qui se désactivent mutuellement au même instant
                // ne peuvent pas laisser la console sans compte actif. SQLite ignore le verrou (un seul écrivain).
                [$compte, $refus] = DB::transaction(function () use ($cible): array {
                    User::query()->actifs()->lockForUpdate()->get(['id']);
                    $compte = User::query()->lockForUpdate()->find($cible);
                    $refus = $compte === null ? 'Compte introuvable.' : $this->refusDesactivation($compte);
                    if ($refus === null) {
                        $compte->forceFill(['desactive_le' => now()])->save();
                    }

                    return [$compte, $refus];
                });
                if ($refus !== null || $compte === null) {
                    $this->terminer(false, $refus ?? 'Compte introuvable.');

                    return true;
                }
                // Hors transaction : fermeture des sessions et révocation des appareils.
                $sessions = config('session.driver') === 'database'
                    ? DB::table(config('session.table', 'sessions'))->where('user_id', $compte->getKey())->delete()
                    : 0;
                $appareils = app(AppareilsDeConfiance::class)->revoquerTout($compte);
                $journal->ecrire('compte.desactive', 'ok', $user, contexte: [
                    'cible' => $compte->getKey(), 'cible_email' => $compte->email, 'sessions' => $sessions, 'appareils' => $appareils,
                ]);
                $this->terminer(true, "Compte de {$compte->name} désactivé.");

                return true;

            case 'comptes.reactiver':
                $compte = User::query()->find($this->cible);
                $this->cible = null;
                if ($compte === null) {
                    $this->terminer(false, 'Compte introuvable.');

                    return true;
                }
                $compte->forceFill(['desactive_le' => null])->save();
                $journal->ecrire('compte.reactive', 'ok', $user, contexte: ['cible' => $compte->getKey(), 'cible_email' => $compte->email]);
                $this->terminer(true, "Compte de {$compte->name} réactivé.");

                return true;
        }

        return false;
    }
}
