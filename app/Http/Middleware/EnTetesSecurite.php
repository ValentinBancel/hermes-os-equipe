<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité sur toutes les réponses, dont la CSP avec nonce (docs/architecture.md,
 * section Sécurité). Le nonce est transmis à Vite et à Livewire (balises script/style).
 */
class EnTetesSecurite
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        $source = "'self' 'nonce-{$nonce}'";
        $connect = '';

        // Développement uniquement : serveur Vite (`npm run dev`) actif.
        if (Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(Vite::hotFile()));
            $source .= ' '.$hot;
            $connect = "; connect-src 'self' ".$hot.' '.preg_replace('#^http#', 'ws', $hot);
        }

        $csp = "default-src 'self'; script-src {$source}; style-src {$source}; img-src 'self' data:; "
            ."frame-ancestors 'none'; form-action 'self'; base-uri 'none'".$connect;

        $response->headers->add([
            'Content-Security-Policy' => $csp,
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), '
                .'microphone=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
