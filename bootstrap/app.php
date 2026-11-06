<?php

use App\Http\Middleware\EnTetesSecurite;
use App\Http\Middleware\ExigerNouveauMotDePasse;
use App\Http\Middleware\ExigerTotp;
use App\Http\Middleware\ListeIpAutorisees;
use App\Http\Middleware\ProtegerConnexion;
use App\Http\Middleware\SessionLimitee;
use App\Http\Middleware\VerifierSignaturePont;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Pont distant (L4) : sans session ni CSRF, hors liste d'IP (le pont sort d'un réseau quelconque).
            Route::middleware('throttle:pont')->group(base_path('routes/pont.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ordre : après TrustProxies (IP réelle), en-têtes avant la liste d'IP (le 403 est aussi protégé).
        $middleware->append([EnTetesSecurite::class, ListeIpAutorisees::class]);
        $middleware->web(append: [SessionLimitee::class]);
        $middleware->alias([
            'totp' => ExigerTotp::class,
            'mdp.provisoire' => ExigerNouveauMotDePasse::class,
            'connexion.protegee' => ProtegerConnexion::class,
            'pont.signe' => VerifierSignaturePont::class,
        ]);
        $middleware->redirectUsersTo('/');
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'pont/v1/*') || $request->expectsJson(),
        );
    })->create();
