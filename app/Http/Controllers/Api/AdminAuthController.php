<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class AdminAuthController extends Controller
{
    /**
     * Nom du cookie HttpOnly qui transporte le token Sanctum.
     * Lisible uniquement côté serveur — protège contre XSS.
     */
    private const AUTH_COOKIE_NAME = 'auth_token';
    private const AUTH_COOKIE_LIFETIME_DAYS = 7;

    /**
     * Construit le cookie HttpOnly.
     *
     * SameSite :
     *  - 'lax' en local (HTTP) ou même origine
     *  - 'none' en prod si l'API et le front sont sur des domaines différents
     *    (ex: front xxx.vercel.app ↔ API app.volontecanada.ca)
     *    → SameSite=None EXIGE Secure=true (HTTPS)
     *
     * Configurable via .env : AUTH_COOKIE_SAMESITE=none|lax|strict
     */
    private function buildAuthCookie(string $token): Cookie
    {
        $isSecure = request()->isSecure();
        $sameSite = env('AUTH_COOKIE_SAMESITE', $isSecure ? 'none' : 'lax');

        return cookie(
            self::AUTH_COOKIE_NAME,
            $token,
            self::AUTH_COOKIE_LIFETIME_DAYS * 24 * 60,
            '/',
            null,
            $isSecure,             // Secure obligatoire si SameSite=None
            true,                  // HttpOnly — JS ne peut PAS le lire
            false,
            $sameSite
        );
    }

    /** Cookie d'expiration immédiate (logout). */
    private function buildForgetAuthCookie(): Cookie
    {
        return cookie()->forget(self::AUTH_COOKIE_NAME, '/');
    }

    /**
     * Login administrateur — pose un cookie HttpOnly + renvoie le token JSON
     * (le token JSON reste pour rétro-compat avec le frontend actuel ; il sera
     * supprimé une fois la bascule 100% cookie terminée).
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $admin = Admin::where('email', $request->email)->first();

            if (!$admin || !Hash::check($request->password, $admin->password)) {
                throw ValidationException::withMessages([
                    'email' => ['Les informations d\'identification sont incorrectes.'],
                ]);
            }

            if (!$admin->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['Votre compte est désactivé. Contactez un super administrateur.'],
                ]);
            }

            $token = $admin->createToken('admin-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'admin' => [
                        'id' => $admin->id,
                        'name' => $admin->name,
                        'email' => $admin->email,
                        'role' => $admin->role,
                    ],
                    'token' => $token, // legacy — sera retiré après bascule 100% cookie
                ],
            ])->withCookie($this->buildAuthCookie($token));
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'La base de données est injoignable. Démarrez MySQL/MariaDB, puis vérifiez DB_HOST, DB_PORT, DB_DATABASE et DB_USERNAME dans le fichier .env du dossier api/.',
            ], 503);
        }
    }

    /**
     * Logout administrateur — révoque le token côté DB et efface le cookie.
     */
    public function logout(Request $request)
    {
        $user = $request->user('admin');
        if ($user) {
            $user->currentAccessToken()?->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie',
        ])->withCookie($this->buildForgetAuthCookie());
    }

    /**
     * Vérifie le mot de passe de l'admin connecté sans rotation de token.
     * Utilisé par le lock screen pour confirmer l'identité (rate-limited côté routes).
     */
    public function verifyPassword(Request $request)
    {
        $request->validate(['password' => 'required|string']);
        $admin = $request->user('admin');
        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect',
            ], 422);
        }
        return response()->json([
            'success' => true,
            'message' => 'Identité vérifiée',
        ]);
    }

    /**
     * Obtenir les informations de l'admin connecté
     */
    public function me(Request $request)
    {
        $admin = $request->user('admin');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'created_at' => $admin->created_at,
            ],
        ]);
    }
}
