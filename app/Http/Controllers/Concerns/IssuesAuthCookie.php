<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie HttpOnly qui transporte le jeton Sanctum (illisible par le JavaScript).
 *
 * SameSite :
 *  - 'lax' en local (HTTP) ou même origine ;
 *  - 'none' en production si l'API et le front sont sur des domaines différents
 *    (SameSite=None exige Secure, donc HTTPS).
 * Configurable via .env : AUTH_COOKIE_SAMESITE=none|lax|strict
 */
trait IssuesAuthCookie
{
    protected function authCookieLifetimeDays(): int
    {
        return 7;
    }

    protected function buildAuthCookie(string $token, string $name = 'auth_token'): Cookie
    {
        $isSecure = request()->isSecure();
        $sameSite = env('AUTH_COOKIE_SAMESITE', $isSecure ? 'none' : 'lax');

        return cookie(
            $name,
            $token,
            $this->authCookieLifetimeDays() * 24 * 60,
            '/',
            null,
            $isSecure,
            true,
            false,
            $sameSite
        );
    }

    protected function buildForgetAuthCookie(string $name = 'auth_token'): Cookie
    {
        return cookie()->forget($name, '/');
    }
}
