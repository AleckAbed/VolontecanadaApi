<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Translate the HttpOnly auth cookie into an Authorization: Bearer header.
 *
 * Sanctum reads tokens from the Authorization header by default. By injecting
 * the cookie value into the header *before* Sanctum runs, we get:
 *  - cookie HttpOnly = invulnerable to XSS (JS can't read it)
 *  - Sanctum keeps working as-is (no config changes to Sanctum itself)
 *
 * If an Authorization header is already provided (legacy frontend, mobile app,
 * test), we don't touch it — backward compatible during the migration.
 */
class AuthCookieToBearer
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->headers->has('Authorization')) {
            $cookieToken = $request->cookie('auth_token');
            if (is_string($cookieToken) && $cookieToken !== '') {
                $request->headers->set('Authorization', 'Bearer ' . $cookieToken);
            }
        }
        return $next($request);
    }
}
