<?php

namespace App\Http\Controllers\Auth;

use App\Audit\Journal;
use App\Auth\Verrouillage;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/**
 * Enrôlement TOTP forcé à la première connexion (et après `hermes-os:reinitialiser-2fa`).
 */
class EnrolementTotpController extends Controller
{
    public function __construct(
        private readonly Journal $journal,
        private readonly Verrouillage $verrouillage,
    ) {}

    public function show(Request $request, EnableTwoFactorAuthentication $activer): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return redirect()->route('accueil');
        }

        // Génère le secret et les codes de récupération une seule fois (pas à chaque affichage).
        $activer($user);

        return view('auth.enrolement', [
            'qrCode' => $user->twoFactorQrCodeSvg(),
            'cle' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        ]);
    }

    public function store(Request $request, ConfirmTwoFactorAuthentication $confirmer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_confirmed_at !== null) {
            return redirect()->route('accueil');
        }

        $request->validate(['code' => ['required', 'string', 'digits:6']]);

        try {
            $confirmer($user, $request->string('code')->toString());
        } catch (ValidationException) {
            $this->journal->ecrire('totp.enrolement_echec', 'failed', $user);
            $this->verrouillage->echec($user->email);

            return back()->withErrors(['code' => 'Code invalide.']);
        }

        $request->session()->regenerate();
        $request->session()->put('hermes.totp_confirme_a', now()->getTimestamp());
        $request->session()->flash('hermes.afficher_codes', true);

        return redirect()->route('totp.codes');
    }

    /**
     * Codes de récupération : affichés une seule fois, juste après l'enrôlement.
     */
    public function codes(Request $request): View|RedirectResponse
    {
        if (! $request->session()->get('hermes.afficher_codes')) {
            return redirect()->route('accueil');
        }

        /** @var User $user */
        $user = $request->user();

        return view('auth.codes', ['codes' => $user->recoveryCodes()]);
    }
}
