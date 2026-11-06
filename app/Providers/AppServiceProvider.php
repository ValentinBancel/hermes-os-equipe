<?php

namespace App\Providers;

use App\Hermes\DemoClient;
use App\Hermes\DistantClient;
use App\Hermes\HermesClient;
use App\Hermes\PontClient;
use App\Http\Middleware\ExigerTotp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Accès aux données Hermes (ADR 0002) : `demo` par défaut (clone neuf, CI), `pont` en production.
        $this->app->singleton(HermesClient::class, function (Application $app): HermesClient {
            $config = (array) config('hermes-os.pont');

            return match ($config['driver'] ?? 'demo') {
                'demo' => new DemoClient(in_array($config['demo_etat'] ?? null, DemoClient::ETATS, true) ? $config['demo_etat'] : 'nominal'),
                'distant' => new DistantClient(
                    perimeSecondes: max(15, (int) ($config['releve_perime_secondes'] ?? 60)),
                    expirationSecondes: max(10, (int) ($config['action_expiration_secondes'] ?? 90)),
                    attenteSecondes: max(0, min(20, (int) ($config['attente_action_secondes'] ?? 8))),
                ),
                'socket', 'pont' => new PontClient(
                    http: $app->make(Http::class),
                    cache: $app->make(CacheFactory::class)->store(),
                    socket: (string) $config['socket'],
                    secret: is_string($config['secret'] ?? null) ? $config['secret'] : null,
                    cacheSecondes: max(3, min(5, (int) ($config['cache_secondes'] ?? 4))),
                ),
                default => throw new InvalidArgumentException('HERMES_PONT_DRIVER doit valoir « demo », « socket » ou « distant ».'),
            };
        });
    }

    public function boot(): void
    {
        // Seuls les proxys de confiance (HERMES_OS_TRUSTED_PROXIES) peuvent fixer X-Forwarded-For / -Proto (ADR 0004).
        TrustProxies::at(config('hermes-os.trusted_proxies'));

        // Les requêtes Livewire réappliquent l'exigence TOTP de la page d'origine.
        Livewire::addPersistentMiddleware([ExigerTotp::class]);

        // Pont distant (L4, R4) : appairage 10/min/IP ; trafic signé large (relevés 15 s + long-poll + résultats).
        RateLimiter::for('pont-appairage', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // Deux limites (secu m1) : un plafond par IP qu'un en-tête choisi par l'appelant ne contourne pas, et un quota
        // par IP + instance qu'on ne peut pas épuiser depuis une autre IP en connaissant l'identifiant d'une instance.
        RateLimiter::for('pont', fn (Request $request) => [
            Limit::perMinute(600)->by('ip:'.$request->ip()),
            Limit::perMinute(240)->by('instance:'.$request->ip().'|'.(string) $request->header('X-Hermes-Instance', '')),
        ]);
    }
}
