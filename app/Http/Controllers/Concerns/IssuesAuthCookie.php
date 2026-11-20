<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie HttpOnly qui transporte le jeton Sanctum (illisible par le JavaScript).
 *
 * SameSite : 'lax' par défaut, en local comme en production (front et API sur le même domaine,
 * ex. app.domaine.ca et api.domaine.ca). 'none' seulement si le front et l'API sont sur des
 * domaines différents : il exige HTTPS et expose aux requêtes forgées depuis un autre site (CSRF).
 * Configurable via .env : AUTH_COOKIE_SAMESITE=lax|strict|none
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
        // « lax » : le front et l'API sont sur le même site ; « none » ouvrirait la porte aux requêtes forgées (CSRF).
        $sameSite = env('AUTH_COOKIE_SAMESITE', 'lax');

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
