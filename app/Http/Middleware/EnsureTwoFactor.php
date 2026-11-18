<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\Cabinet;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Double authentification obligatoire (2.7) : quand le cabinet l'exige, un membre
 * qui ne l'a pas encore activée ne peut que l'activer (et se déconnecter).
 */
class EnsureTwoFactor
{
    private const ALLOWED = [
        'api/admin/me',
        'api/admin/logout',
        'api/admin/two-factor',
        'api/admin/two-factor/setup',
        'api/admin/two-factor/confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        if ($admin instanceof Admin && ! $admin->hasTwoFactor()
            && ! in_array($request->route()?->uri(), self::ALLOWED, true)
            && Cabinet::whereKey($admin->cabinet_id)->value('require_two_factor')) {
            return response()->json([
                'success' => false,
                'code' => 'two_factor_setup_required',
                'message' => 'Votre cabinet exige la double authentification. Activez-la pour continuer.',
            ], 403);
        }

        return $next($request);
    }
}
