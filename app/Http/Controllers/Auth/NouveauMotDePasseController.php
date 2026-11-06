<?php

namespace App\Http\Controllers\Auth;

use App\Audit\Journal;
use App\Auth\AppareilsDeConfiance;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Remplacement obligatoire du mot de passe provisoire à la première connexion.
 */
class NouveauMotDePasseController extends Controller
{
    public function __construct(private readonly Journal $journal) {}

    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->mot_de_passe_provisoire) {
            return redirect()->route('accueil');
        }

        return view('auth.nouveau-mot-de-passe');
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->mot_de_passe_provisoire) {
            return redirect()->route('accueil');
        }

        $request->validate([
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min((int) config('hermes-os.auth.mot_de_passe_min'))->uncompromised(),
                function (string $attribut, mixed $valeur, \Closure $echec) use ($user) {
                    if (is_string($valeur) && Hash::check($valeur, $user->password)) {
                        $echec('Le nouveau mot de passe doit être différent du mot de passe provisoire.');
                    }
                },
            ],
        ], [], ['password' => 'nouveau mot de passe']);

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'mot_de_passe_provisoire' => false,
        ])->save();

        app(AppareilsDeConfiance::class)->revoquerTout($user);
        $request->session()->regenerate();
        $this->journal->ecrire('compte.mot_de_passe_change', user: $user, contexte: ['raison' => 'provisoire']);

        return redirect()->route('totp.enrolement');
    }
}
