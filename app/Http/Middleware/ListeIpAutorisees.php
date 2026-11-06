<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liste d'IP optionnelle (HERMES_OS_IP_ALLOWLIST, CIDR séparés par des virgules).
 * Vide = désactivée (ADR 0004). /up reste joignable pour le healthcheck du conteneur.
 */
class ListeIpAutorisees
{
    public function handle(Request $request, Closure $next): Response
    {
        $plages = config('hermes-os.ip_allowlist', []);

        if ($plages !== [] && ! $request->is('up', 'pont/*') && ! IpUtils::checkIp((string) $request->ip(), $plages)) {
            abort(403);
        }

        return $next($request);
    }
}
