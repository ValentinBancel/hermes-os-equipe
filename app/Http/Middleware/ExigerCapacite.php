<?php

namespace App\Http\Middleware;

use App\Interface\Capacites;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Page optionnelle (coffre, messagerie) absente du Hermes relié : retour à l'accueil plutôt qu'une 404. */
class ExigerCapacite
{
    public function handle(Request $request, Closure $next, string $capacite): Response
    {
        $disponible = match ($capacite) {
            'coffre' => Capacites::coffre(),
            'messagerie' => Capacites::messagerie(),
            default => true,
        };

        if (! $disponible) {
            return redirect()->route('accueil');
        }

        return $next($request);
    }
}
