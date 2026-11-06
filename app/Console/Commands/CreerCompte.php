<?php

namespace App\Console\Commands;

use App\Audit\Journal;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Crée un compte de la console : le premier, puis les suivants (L3, C8). Les comptes suivants peuvent aussi être
 * ajoutés depuis la page Compte ; il n'existe aucune inscription libre.
 * Le mot de passe est demandé de façon masquée, ou lu sur l'entrée standard avec
 * --mot-de-passe-stdin (scripts) : jamais en argument, pour ne pas finir dans l'historique.
 */
#[Signature('hermes-os:compte
    {--email= : Adresse e-mail (identifiant de connexion)}
    {--nom= : Nom affiché}
    {--mot-de-passe-stdin : Lire le mot de passe sur l\'entrée standard}
    {--provisoire : Générer un mot de passe provisoire (affiché une fois, à changer à la première connexion)}')]
#[Description('Crée un compte de la console (TOTP enrôlé à la première connexion)')]
class CreerCompte extends Command
{
    public function handle(Journal $journal): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Adresse e-mail'));
        $nom = (string) ($this->option('nom') ?: $this->ask('Nom affiché', 'Administrateur'));

        $provisoire = (bool) $this->option('provisoire');

        if ($provisoire) {
            $motDePasse = $this->genererProvisoire();
            $confirmation = $motDePasse;
        } elseif ($this->option('mot-de-passe-stdin')) {
            $motDePasse = rtrim((string) $this->lireEntreeStandard(), "\r\n");
            $confirmation = $motDePasse;
        } else {
            $motDePasse = (string) $this->secret('Mot de passe (16 caractères minimum)');
            $confirmation = (string) $this->secret('Confirmer le mot de passe');
        }

        $validation = Validator::make(
            ['email' => mb_strtolower(trim($email)), 'name' => $nom, 'password' => $motDePasse, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
                'name' => ['required', 'string', 'max:255'],
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min((int) config('hermes-os.auth.mot_de_passe_min'))->uncompromised(),
                ],
            ],
        );

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $nom,
            'email' => mb_strtolower(trim($email)),
            'password' => $motDePasse,
        ]);
        $user->forceFill(['mot_de_passe_provisoire' => $provisoire])->save();

        $journal->ecrire('compte.cree', user: $user, contexte: ['source' => 'cli']);

        $this->info("Compte créé pour {$user->email}. Le TOTP sera enrôlé à la première connexion.");

        if ($provisoire) {
            $this->line("Mot de passe provisoire : {$motDePasse}");
            $this->warn('Affiché une seule fois. Il devra être remplacé à la première connexion.');
        }

        return self::SUCCESS;
    }

    /**
     * 4 groupes de 5 caractères sans ambiguïté (pas de 0/O, 1/l/I) : facile à recopier, ~100 bits.
     */
    protected function genererProvisoire(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $groupes = [];
        for ($g = 0; $g < 4; $g++) {
            $groupe = '';
            for ($i = 0; $i < 5; $i++) {
                $groupe .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groupes[] = $groupe;
        }

        return implode('-', $groupes);
    }

    protected function lireEntreeStandard(): string|false
    {
        return stream_get_contents(STDIN);
    }
}
