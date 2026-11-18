<?php

namespace App\Http\Middleware;

use App\Models\Cabinet;
use App\Support\Tenancy\CurrentCabinet;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixe le cabinet courant à partir de l'utilisateur authentifié
 * (admin, collaborateur ou client).
 *
 * Usage :
 *  - 'cabinet'          après 'auth:…' : utilisateur obligatoire, sinon 401 ;
 *  - 'cabinet:optional' sur une route publique : fixe le cabinet si un jeton
 *    valide est présent, sinon laisse le contexte vide (aucune donnée).
 *
 * Refuse l'accès (403) si le cabinet de l'utilisateur est suspendu.
 */
class ResolveCabinetFromUser
{
    private const GUARDS = ['admin', 'collaborator', 'client'];

    public function handle(Request $request, Closure $next, ?string $mode = null)
    {
        $user = null;
        foreach (self::GUARDS as $guard) {
            $user = Auth::guard($guard)->user();
            if ($user) {
                break;
            }
        }

        // Compte désactivé : traité comme non connecté, même avec un jeton encore valide.
        if ($user && isset($user->is_active) && ! $user->is_active) {
            $user = null;
        }

        if (! $user || empty($user->cabinet_id)) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $cabinet = Cabinet::find($user->cabinet_id);
        if (! $cabinet || ! $cabinet->is_active) {
            return response()->json([
                'message' => 'Ce cabinet est suspendu. Contactez le support.',
            ], 403);
        }

        CurrentCabinet::set($cabinet->id);

        return $next($request);
    }
}
