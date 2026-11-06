<?php

namespace App\Providers;

use App\Auth\ConnexionAvecAppareilDeConfiance;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Passkeys;

/**
 * Fortify sans ses routes ni ses vues : seules connexion, défi TOTP et déconnexion sont
 * exposées (routes/web.php), avec nos propres écrans (ADR 0003).
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
        Passkeys::ignoreRoutes();
    }

    public function boot(): void
    {
        // Défi TOTP sauté pour un appareil de confiance sur un réseau de confiance (ADR 0003, amendement). Lié au boot :
        // le register() de Fortify lie déjà son implémentation.
        $this->app->singleton(RedirectsIfTwoFactorAuthenticatable::class, ConnexionAvecAppareilDeConfiance::class);

        Fortify::loginView(fn () => view('auth.connexion'));
        Fortify::twoFactorChallengeView(fn () => view('auth.code'));

        // Un compte désactivé (L3) est refusé avec le message générique des identifiants invalides : rien ne distingue
        // « inconnu », « mauvais mot de passe » et « désactivé ». Le hachage est toujours vérifié (pas d'écart de temps).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', mb_strtolower(trim((string) $request->input(Fortify::username()))))->first();
            $valide = Hash::check((string) $request->input('password'), $user?->password ?? self::leurre());

            return $valide && $user?->estActif() ? $user : null;
        });

        $parMinute = (int) config('hermes-os.auth.tentatives_par_minute');

        RateLimiter::for('login', function (Request $request) use ($parMinute) {
            $cle = Str::transliterate(Str::lower((string) $request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute($parMinute)->by($cle);
        });

        RateLimiter::for('two-factor', function (Request $request) use ($parMinute) {
            $cle = $request->session()->get('login.id') ?? $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute($parMinute)->by('totp|'.$cle);
        });
    }

    /**
     * Hachage d'un mot de passe aléatoire, comparé quand l'e-mail est inconnu. Mis en cache persistant (B1 secu) :
     * un processus PHP-FPM par requête recalculerait sinon le hachage à chaque e-mail inconnu (≈ 2 × plus lent).
     */
    private static function leurre(): string
    {
        return Cache::rememberForever('hermes-os:leurre-hash', fn () => Hash::make(Str::random(40)));
    }
}
