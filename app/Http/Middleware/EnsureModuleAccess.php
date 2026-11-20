<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique côté API les menus que le propriétaire a masqués à un membre (voir Modules).
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();
        $uri = (string) $request->route()?->uri();
        $module = $admin instanceof Admin ? Modules::forUri($uri) : null;

        if ($module !== null) {
            $sharedRead = (Modules::ALL[$module]['shared_read'] ?? false) && $request->isMethodSafe();
            if (! $sharedRead && ! (Modules::effective($admin)[$module] ?? true)) {
                return response()->json([
                    'success' => false,
                    'code' => 'module_disabled',
                    'message' => 'Ce menu n’est pas ouvert pour votre compte. Demandez au propriétaire du cabinet.',
                ], 403);
            }
        }

        return $next($request);
    }
}
