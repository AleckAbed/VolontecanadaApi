<?php

namespace App\Http\Controllers\Api\Platform;

use App\Http\Controllers\Concerns\IssuesAuthCookie;
use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PlatformAuthController extends Controller
{
    use IssuesAuthCookie;

    /** Cookie distinct de celui des cabinets : les deux sessions ne se mélangent pas. */
    public const COOKIE = 'platform_token';

    protected function authCookieLifetimeDays(): int
    {
        return 1;
    }

    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        $admin = PlatformAdmin::where('email', $request->email)->first();
        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            throw ValidationException::withMessages(['email' => ['Les informations d\'identification sont incorrectes.']]);
        }

        // La console voit tous les cabinets : double authentification par application
        // obligatoire en production (activée sur le serveur : php artisan platform:two-factor).
        if ($admin->hasTwoFactor()) {
            return response()->json(['success' => true, 'data' => [
                'two_factor_required' => true,
                'challenge' => TwoFactor::createChallenge('platform', $admin->id),
            ]]);
        }
        if (app()->isProduction()) {
            return response()->json([
                'success' => false,
                'message' => 'La double authentification est obligatoire pour la console. Activez-la sur le serveur : php artisan platform:two-factor ' . $admin->email,
            ], 403);
        }

        return $this->openSession($admin, ['two_factor_missing' => true]);
    }

    /** Second temps de la connexion : code de l'application ou code de secours. */
    public function verifyTwoFactor(Request $request)
    {
        $data = $request->validate([
            'challenge' => 'required|string|size:48',
            'code' => 'nullable|string|max:10',
            'recovery_code' => 'nullable|string|max:20',
        ]);
        $id = TwoFactor::challengeAccount('platform', $data['challenge']);
        $admin = $id ? PlatformAdmin::find($id) : null;
        if (! $admin || ! $admin->hasTwoFactor()) {
            return response()->json(['success' => false, 'code' => 'challenge_expired', 'message' => 'La vérification a expiré. Reconnectez-vous.'], 422);
        }

        $ok = ! empty($data['recovery_code'])
            ? TwoFactor::useRecoveryCode($admin, $data['recovery_code'])
            : (! empty($data['code']) && TwoFactor::verifyFor($admin, $data['code'], ''));
        if (! $ok) {
            return TwoFactor::failChallenge('platform', $data['challenge'])
                ? response()->json(['success' => false, 'message' => 'Code incorrect.'], 422)
                : response()->json(['success' => false, 'code' => 'challenge_expired', 'message' => 'Trop d’essais. Reconnectez-vous.'], 429);
        }
        TwoFactor::forgetChallenge('platform', $data['challenge']);

        return $this->openSession($admin);
    }

    private function openSession(PlatformAdmin $admin, array $extra = [])
    {
        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken('platform-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => ['admin' => $this->format($admin)] + $extra,
        ])->withCookie($this->buildAuthCookie($token, self::COOKIE));
    }

    public function me(Request $request)
    {
        return response()->json(['success' => true, 'data' => $this->format($request->user('platform'))]);
    }

    public function logout(Request $request)
    {
        $request->user('platform')?->currentAccessToken()?->delete();

        return response()->json(['success' => true])->withCookie($this->buildForgetAuthCookie(self::COOKIE));
    }

    private function format(PlatformAdmin $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'two_factor' => $a->hasTwoFactor()];
    }
}
