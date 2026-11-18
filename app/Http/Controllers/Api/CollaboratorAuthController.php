<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Models\Collaborator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Auth pour collaborateurs (compte créé par l'admin).
 * Utilise le même cookie HttpOnly `auth_token` que les autres guards —
 * Sanctum résout le bon modèle par le tokenable_type.
 */
class CollaboratorAuthController extends Controller
{
    private const AUTH_COOKIE_NAME = 'auth_token';
    private const AUTH_COOKIE_LIFETIME_DAYS = 7;

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
            $isSecure,
            true,
            false,
            $sameSite
        );
    }

    private function buildForgetAuthCookie(): Cookie
    {
        return cookie()->forget(self::AUTH_COOKIE_NAME, '/');
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Un même courriel peut être collaborateur de plusieurs cabinets : on
            // retient le compte dont le mot de passe correspond.
            $collab = Collaborator::acrossCabinets()->where('email', $request->email)->get()
                ->first(fn ($c) => $c->password && Hash::check($request->password, $c->password));

            if (!$collab) {
                throw ValidationException::withMessages([
                    'email' => ['Les informations d\'identification sont incorrectes.'],
                ]);
            }

            if (!$collab->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['Votre compte est désactivé. Contactez l\'administrateur.'],
                ]);
            }

            $token = $collab->createToken('collab-token')->plainTextToken;
            Audit::log('login', 'collaborator', $collab->id, [], $collab->cabinet_id, ['type' => 'collaborator', 'id' => $collab->id, 'name' => trim("{$collab->first_name} {$collab->last_name}")]);

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'collaborator' => [
                        'id' => $collab->id,
                        'first_name' => $collab->first_name,
                        'last_name' => $collab->last_name,
                        'email' => $collab->email,
                    ],
                    'token' => $token, // legacy parallèle
                ],
            ])->withCookie($this->buildAuthCookie($token));
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'La base de données est injoignable.',
            ], 503);
        }
    }

    public function logout(Request $request)
    {
        $user = $request->user('collaborator');
        if ($user) {
            $user->currentAccessToken()?->delete();
        }
        return response()->json(['success' => true, 'message' => 'Déconnexion réussie'])
            ->withCookie($this->buildForgetAuthCookie());
    }

    public function me(Request $request)
    {
        $collab = $request->user('collaborator');
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $collab->id,
                'first_name' => $collab->first_name,
                'last_name' => $collab->last_name,
                'email' => $collab->email,
                'phone' => $collab->phone,
                'is_active' => $collab->is_active,
            ],
        ]);
    }
}
