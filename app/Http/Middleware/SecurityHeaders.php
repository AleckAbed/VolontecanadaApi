<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité sur toutes les réponses de l'API.
 *
 * L'API ne sert que du JSON et des fichiers : aucune page ne doit pouvoir l'afficher dans un
 * cadre, deviner un type de fichier, ni charger quoi que ce soit.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Resource-Policy', 'same-site');

        // Les PDF sont affichés par le front dans son propre visualiseur, jamais encadrés depuis l'API.
        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'DENY');
        }
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        if (config('security.headers.hsts') && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $headers->remove('X-Powered-By');

        return $response;
    }
}
