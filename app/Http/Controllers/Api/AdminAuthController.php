<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\IssuesAuthCookie;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\Roles;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    use IssuesAuthCookie;

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

            // Recherche sans filtre : c'est l'admin qui détermine le cabinet courant.
            $admin = Admin::acrossCabinets()->where('email', $request->email)->first();

            // Membre invité sans mot de passe encore choisi : mêmes erreurs qu'un mauvais mot de passe.
            if (!$admin || !$admin->password || !Hash::check($request->password, $admin->password)) {
                if ($admin) {
                    Audit::log('login_failed', 'admin', $admin->id, [], $admin->cabinet_id, ['type' => 'public', 'id' => null, 'name' => 'Tentative de connexion']);
                }
                throw ValidationException::withMessages([
                    'email' => ['Les informations d\'identification sont incorrectes.'],
                ]);
            }

            if (!$admin->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['Votre compte est désactivé. Contactez le propriétaire de votre cabinet.'],
                ]);
            }

            $cabinet = Cabinet::find($admin->cabinet_id);
            if (!$cabinet || !$cabinet->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['Ce cabinet est suspendu. Contactez le support de la plateforme.'],
                ]);
            }

            // Double authentification (2.7) : la session ne s'ouvre qu'après le code.
            if ($admin->hasTwoFactor()) {
                return TwoFactorController::startChallenge($admin);
            }

            Audit::log('login', 'admin', $admin->id, [], $admin->cabinet_id, ['type' => 'admin', 'id' => $admin->id, 'name' => $admin->name]);

            return $this->startSession($admin, 'Connexion réussie');
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
     * Ouvre une session : jeton Sanctum + cookie HttpOnly.
     * Partagé avec l'inscription d'un cabinet (connexion immédiate du propriétaire).
     */
    public function startSession(Admin $admin, string $message, int $status = 200): JsonResponse
    {
        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'admin' => $this->formatAdmin($admin),
                'token' => $token, // legacy — sera retiré après bascule 100% cookie
            ],
        ], $status)->withCookie($this->buildAuthCookie($token));
    }

    /**
     * Logout administrateur — révoque le token côté DB et efface le cookie.
     */
    public function logout(Request $request)
    {
        $user = $request->user('admin');
        if ($user) {
            Audit::log('logout', 'admin', $user->id);
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
        if (!$admin || !$admin->password || !Hash::check($request->password, $admin->password)) {
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
     * Obtenir les informations de l'admin connecté (rôle, droits, cabinet).
     */
    public function me(Request $request)
    {
        $admin = $request->user('admin');

        return response()->json([
            'success' => true,
            'data' => $this->formatAdmin($admin) + [
                'is_active' => $admin->is_active,
                'created_at' => $admin->created_at,
            ],
        ]);
    }

    private function formatAdmin(Admin $admin): array
    {
        $cabinet = Cabinet::find($admin->cabinet_id);

        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'role_label' => Roles::label($admin->role),
            'permissions' => Roles::permissions($admin->role),
            'cabinet' => $cabinet ? ['id' => $cabinet->id, 'name' => $cabinet->name, 'slug' => $cabinet->slug, 'branding' => $cabinet->branding()] : null,
            'two_factor' => [
                'enabled' => $admin->hasTwoFactor(),
                'required' => (bool) $cabinet?->require_two_factor,
            ],
        ];
    }
}
