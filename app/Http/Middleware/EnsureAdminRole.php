<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;

/**
 * Restreint une route à certains rôles du personnel.
 * Usage : ->middleware('role:owner,admin')
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $admin = $request->user('admin');

        if (! $admin || ! in_array($admin->role, $roles, true)) {
            $allowed = implode(', ', array_map(fn ($r) => Roles::label($r), $roles));

            return response()->json([
                'success' => false,
                'message' => "Votre rôle ne permet pas cette action (réservée à : {$allowed}).",
            ], 403);
        }

        return $next($request);
    }
}
