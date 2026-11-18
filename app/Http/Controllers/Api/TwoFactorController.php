<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TwoFactorCodeMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\Audit;
use App\Support\Roles;
use App\Support\Tenancy\CurrentCabinet;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Double authentification du personnel (2.7).
 *
 * Connexion : après le mot de passe, AdminAuthController::login renvoie un « défi »
 * (jeton à usage unique, 10 minutes) au lieu d'ouvrir la session ; la session ne s'ouvre
 * qu'ici, avec le code de l'application, du courriel ou un code de secours.
 */
class TwoFactorController extends Controller
{
    private const CHALLENGE_TTL_MINUTES = 10;

    private const CHALLENGE_MAX_ATTEMPTS = 5;

    // ─── Connexion ──────────────────────────────────────────────────────────

    /** Crée le défi de connexion et envoie le code par courriel si c'est la méthode choisie. */
    public static function startChallenge(Admin $admin): JsonResponse
    {
        $challenge = Str::random(48);
        Cache::put(self::challengeKey($challenge), ['admin_id' => $admin->id, 'attempts' => 0], now()->addMinutes(self::CHALLENGE_TTL_MINUTES));
        if ($admin->two_factor_method === 'email') {
            self::sendEmailCode($admin, "login:{$challenge}");
        }

        return response()->json([
            'success' => true,
            'message' => 'Entrez votre code de vérification.',
            'data' => [
                'two_factor_required' => true,
                'challenge' => $challenge,
                'method' => $admin->two_factor_method,
                'email_hint' => $admin->two_factor_method === 'email' ? TwoFactor::maskEmail($admin->email) : null,
            ],
        ]);
    }

    public function verifyLogin(Request $request, AdminAuthController $auth)
    {
        $data = $request->validate([
            'challenge' => 'required|string|size:48',
            'code' => 'nullable|string|max:10',
            'recovery_code' => 'nullable|string|max:20',
        ]);
        $key = self::challengeKey($data['challenge']);
        $entry = Cache::get($key);
        $admin = $entry ? Admin::acrossCabinets()->find($entry['admin_id']) : null;
        if (! $admin || ! $admin->is_active || ! $admin->hasTwoFactor()) {
            return $this->expired();
        }

        $actor = ['type' => 'admin', 'id' => $admin->id, 'name' => $admin->name];
        $ok = false;
        $usedRecovery = false;
        if (! empty($data['recovery_code'])) {
            $ok = $usedRecovery = TwoFactor::useRecoveryCode($admin, $data['recovery_code']);
        } elseif (! empty($data['code'])) {
            $ok = TwoFactor::verifyFor($admin, $data['code'], "login:{$data['challenge']}");
        }

        if (! $ok) {
            Audit::log('two_factor_failed', 'admin', $admin->id, [], $admin->cabinet_id, $actor);
            $entry['attempts']++;
            if ($entry['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS) {
                Cache::forget($key);

                return response()->json(['success' => false, 'message' => 'Trop d’essais. Reconnectez-vous avec votre mot de passe.', 'code' => 'challenge_expired'], 429);
            }
            Cache::put($key, $entry, now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

            return response()->json(['success' => false, 'message' => 'Code incorrect ou expiré.'], 422);
        }

        Cache::forget($key);
        if ($usedRecovery) {
            Audit::log('recovery_code_used', 'admin', $admin->id, [], $admin->cabinet_id, $actor);
        }
        Audit::log('login', 'admin', $admin->id, ['two_factor'], $admin->cabinet_id, $actor);

        $response = $auth->startSession($admin, 'Connexion réussie');
        if ($usedRecovery) {
            $left = count($admin->fresh()->two_factor_recovery_codes ?? []);
            $data = $response->getData(true);
            $data['data']['recovery_codes_left'] = $left;
            $response->setData($data);
        }

        return $response;
    }

    public function resendLoginCode(Request $request)
    {
        $data = $request->validate(['challenge' => 'required|string|size:48']);
        $entry = Cache::get(self::challengeKey($data['challenge']));
        $admin = $entry ? Admin::acrossCabinets()->find($entry['admin_id']) : null;
        if (! $admin || $admin->two_factor_method !== 'email') {
            return $this->expired();
        }
        self::sendEmailCode($admin, "login:{$data['challenge']}");

        return response()->json(['success' => true, 'message' => 'Un nouveau code vous a été envoyé.']);
    }

    // ─── Réglage par la personne connectée ──────────────────────────────────

    public function status(Request $request)
    {
        $admin = $request->user();

        return response()->json(['success' => true, 'data' => [
            'enabled' => $admin->hasTwoFactor(),
            'method' => $admin->hasTwoFactor() ? $admin->two_factor_method : null,
            'confirmed_at' => $admin->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_left' => $admin->hasTwoFactor() ? count($admin->two_factor_recovery_codes ?? []) : 0,
            'required_by_cabinet' => (bool) Cabinet::whereKey($admin->cabinet_id)->value('require_two_factor'),
            'can_manage' => in_array($admin->role, Roles::MANAGERS, true),
        ]]);
    }

    /** Démarre l'activation (ou le changement de méthode) : rien ne change avant la confirmation. */
    public function setup(Request $request)
    {
        $data = $request->validate(['method' => 'required|in:totp,email', 'password' => 'required|string']);
        $admin = $request->user();
        $this->checkPassword($admin, $data['password']);

        $pending = ['method' => $data['method'], 'secret' => $data['method'] === 'totp' ? TwoFactor::generateSecret() : null];
        Cache::put("2fa-setup:{$admin->id}", $pending, now()->addMinutes(15));

        if ($data['method'] === 'email') {
            self::sendEmailCode($admin, "setup:{$admin->id}");

            return response()->json(['success' => true, 'data' => ['method' => 'email', 'email_hint' => TwoFactor::maskEmail($admin->email)]]);
        }

        $issuer = Cabinet::whereKey($admin->cabinet_id)->value('name') ?: TwoFactor::ISSUER_FALLBACK;

        return response()->json(['success' => true, 'data' => [
            'method' => 'totp',
            'secret' => $pending['secret'],
            'otpauth_uri' => TwoFactor::otpauthUri($pending['secret'], $admin->email, $issuer),
        ]]);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $admin = $request->user();
        $pending = Cache::get("2fa-setup:{$admin->id}");
        if (! $pending) {
            return response()->json(['success' => false, 'message' => 'Recommencez l’activation : le délai est écoulé.'], 422);
        }
        $ok = $pending['method'] === 'totp'
            ? TwoFactor::verifyTotp($pending['secret'], $data['code'], "2fa-totp-last:{$admin->id}")
            : TwoFactor::verifyEmailCode("setup:{$admin->id}", $data['code']);
        if (! $ok) {
            return response()->json(['success' => false, 'message' => 'Code incorrect. Vérifiez l’heure de votre téléphone ou demandez un nouveau code.'], 422);
        }

        [$plain, $hashes] = TwoFactor::generateRecoveryCodes();
        $admin->forceFill([
            'two_factor_method' => $pending['method'],
            'two_factor_secret' => $pending['secret'],
            'two_factor_recovery_codes' => $hashes,
            'two_factor_confirmed_at' => now(),
        ])->save();
        Cache::forget("2fa-setup:{$admin->id}");
        Audit::log('two_factor_enabled', 'admin', $admin->id, [$pending['method']]);

        return response()->json(['success' => true, 'message' => 'Double authentification activée.', 'data' => ['recovery_codes' => $plain]]);
    }

    public function disable(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        $admin = $request->user();
        $this->checkPassword($admin, $data['password']);
        if (Cabinet::whereKey($admin->cabinet_id)->value('require_two_factor')) {
            return response()->json(['success' => false, 'message' => 'Votre cabinet exige la double authentification : vous pouvez changer de méthode, pas la désactiver.'], 422);
        }
        $admin->forceFill(['two_factor_method' => null, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::log('two_factor_disabled', 'admin', $admin->id);

        return response()->json(['success' => true, 'message' => 'Double authentification désactivée.']);
    }

    public function regenerateRecoveryCodes(Request $request)
    {
        $data = $request->validate(['password' => 'required|string']);
        $admin = $request->user();
        $this->checkPassword($admin, $data['password']);
        if (! $admin->hasTwoFactor()) {
            return response()->json(['success' => false, 'message' => 'Activez d’abord la double authentification.'], 422);
        }
        [$plain, $hashes] = TwoFactor::generateRecoveryCodes();
        $admin->forceFill(['two_factor_recovery_codes' => $hashes])->save();

        return response()->json(['success' => true, 'message' => 'Nouveaux codes de secours créés. Les anciens ne fonctionnent plus.', 'data' => ['recovery_codes' => $plain]]);
    }

    // ─── Gestionnaires ──────────────────────────────────────────────────────

    /** Exiger (ou non) la double authentification pour toute l'équipe. */
    public function setRequirement(Request $request)
    {
        $data = $request->validate(['require_two_factor' => 'required|boolean']);
        $admin = $request->user();
        if ($data['require_two_factor'] && ! $admin->hasTwoFactor()) {
            return response()->json(['success' => false, 'message' => 'Activez d’abord la double authentification pour vous-même.'], 422);
        }
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $cabinet->forceFill(['require_two_factor' => $data['require_two_factor']])->save();
        $missing = Admin::where('is_active', true)->get()->reject->hasTwoFactor()->count();

        return response()->json(['success' => true, 'message' => $data['require_two_factor']
            ? "La double authentification est maintenant obligatoire. {$missing} membre(s) devront l’activer à leur prochaine action."
            : 'La double authentification n’est plus obligatoire.']);
    }

    /** Téléphone perdu : un gestionnaire retire la double authentification d'un membre et ferme ses sessions. */
    public function resetMember(Request $request, int $id)
    {
        $member = Admin::findOrFail($id);
        $me = $request->user();
        if ($member->role === Roles::OWNER && $me->role !== Roles::OWNER) {
            return response()->json(['success' => false, 'message' => 'Seul un propriétaire peut réinitialiser un propriétaire.'], 403);
        }
        $member->forceFill(['two_factor_method' => null, 'two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $member->tokens()->delete();
        Audit::log('two_factor_reset', 'admin', $member->id);

        return response()->json(['success' => true, 'message' => "Double authentification réinitialisée pour {$member->name}. Ses sessions sont fermées ; il devra la réactiver."]);
    }

    // ─── Interne ────────────────────────────────────────────────────────────

    private static function challengeKey(string $challenge): string
    {
        return '2fa-challenge:' . hash('sha256', $challenge);
    }

    private static function sendEmailCode(Admin $admin, string $key): void
    {
        $code = TwoFactor::issueEmailCode($key);
        // Envoi immédiat : le code doit arriver pendant que la personne attend.
        Mail::to($admin->email)->sendNow(new TwoFactorCodeMail($admin, $code));
    }

    private function checkPassword(Admin $admin, string $password): void
    {
        if (! $admin->password || ! Hash::check($password, $admin->password)) {
            abort(response()->json(['success' => false, 'message' => 'Mot de passe incorrect.', 'errors' => ['password' => ['Mot de passe incorrect.']]], 422));
        }
    }

    private function expired(): JsonResponse
    {
        return response()->json(['success' => false, 'code' => 'challenge_expired', 'message' => 'La vérification a expiré. Reconnectez-vous.'], 422);
    }
}
