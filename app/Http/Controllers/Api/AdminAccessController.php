<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AdminPasswordResetMail;
use App\Mail\TwoFactorCodeMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\AdminAccessTokens;
use App\Support\Audit;
use App\Support\TwoFactor;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Liens d'accès du personnel (routes publiques, limitées en débit) :
 *  - mot de passe oublié ;
 *  - vérification d'un lien (invitation ou réinitialisation) ;
 *  - choix du mot de passe via ce lien, avec réglage de la double authentification
 *    quand le cabinet l'exige (sinon la personne serait bloquée à sa première action) ;
 *    la session s'ouvre ensuite directement, sans repasser par l'écran de connexion.
 */
class AdminAccessController extends Controller
{
    /**
     * Envoie un lien de réinitialisation. Répond toujours la même chose, que le
     * courriel existe ou non, pour ne pas révéler qui a un compte.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $admin = CurrentCabinet::withoutScope(
            fn () => Admin::acrossCabinets()->where('email', $request->input('email'))->first()
        );

        if ($admin && $admin->is_active) {
            $cabinet = Cabinet::find($admin->cabinet_id);
            if ($cabinet && $cabinet->is_active) {
                // Un membre invité qui n'a jamais activé son compte reçoit une invitation, pas une réinitialisation.
                $purpose = $admin->password ? AdminAccessTokens::RESET : AdminAccessTokens::INVITE;
                $token = AdminAccessTokens::issue($admin, $purpose);
                try {
                    Mail::to($admin->email)->send(new AdminPasswordResetMail($admin, $cabinet, $token, $purpose));
                } catch (\Throwable $e) {
                    Log::error('Erreur envoi réinitialisation mot de passe', ['admin_id' => $admin->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Si un compte existe pour ce courriel, un lien de réinitialisation vient d\'être envoyé. Il est valable 1 heure.',
        ]);
    }

    /** Vérifie un lien et renvoie de quoi personnaliser la page. */
    public function check(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $admin = AdminAccessTokens::find($request->input('token'));
        if (! $admin) {
            return response()->json([
                'success' => false,
                'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau.',
            ], 410);
        }

        $cabinet = Cabinet::find($admin->cabinet_id);

        return response()->json([
            'success' => true,
            'data' => [
                'purpose' => $admin->access_token_purpose,
                'name' => $admin->name,
                'email' => $admin->email,
                'cabinet_name' => $cabinet?->name,
                'two_factor_required' => $this->mustSetUpTwoFactor($admin),
                'has_two_factor' => $admin->hasTwoFactor(),
            ],
        ]);
    }

    /**
     * Prépare la double authentification pendant l'activation : clé pour l'application
     * (code QR) ou code envoyé par courriel. Rien n'est enregistré avant « complete ».
     */
    public function twoFactorSetup(Request $request)
    {
        $data = $request->validate(['token' => 'required|string', 'method' => 'required|in:totp,email']);
        $admin = AdminAccessTokens::find($data['token']);
        if (! $admin) {
            return response()->json(['success' => false, 'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau.'], 410);
        }

        $pending = ['method' => $data['method'], 'secret' => $data['method'] === 'totp' ? TwoFactor::generateSecret() : null];
        Cache::put(self::setupKey($admin), $pending, now()->addMinutes(15));

        if ($data['method'] === 'email') {
            $code = TwoFactor::issueEmailCode(self::setupKey($admin) . ':email');
            // Envoi immédiat : la personne attend le code devant l'écran.
            Mail::to($admin->email)->sendNow(new TwoFactorCodeMail($admin, $code));

            return response()->json(['success' => true, 'data' => ['method' => 'email', 'email_hint' => TwoFactor::maskEmail($admin->email)]]);
        }

        $issuer = Cabinet::whereKey($admin->cabinet_id)->value('name') ?: TwoFactor::ISSUER_FALLBACK;

        return response()->json(['success' => true, 'data' => [
            'method' => 'totp',
            'secret' => $pending['secret'],
            'otpauth_uri' => TwoFactor::otpauthUri($pending['secret'], $admin->email, $issuer),
        ]]);
    }

    /** Applique le nouveau mot de passe et invalide le lien. */
    public function complete(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => AdminAccessTokens::passwordRules(),
            'two_factor_code' => 'nullable|string|max:10',
        ], AdminAccessTokens::passwordMessages());

        $admin = AdminAccessTokens::find($request->input('token'));
        if (! $admin) {
            return response()->json([
                'success' => false,
                'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau.',
            ], 410);
        }

        // Cabinet qui exige la 2FA : elle se règle ici, avant d'enregistrer quoi que ce soit.
        $recoveryCodes = null;
        if ($this->mustSetUpTwoFactor($admin)) {
            $pending = Cache::get(self::setupKey($admin));
            $code = (string) $request->input('two_factor_code', '');
            if (! $pending || $code === '') {
                return response()->json([
                    'success' => false,
                    'code' => 'two_factor_setup_required',
                    'message' => 'Votre cabinet exige la double authentification : configurez-la pour terminer.',
                ], 422);
            }
            $ok = $pending['method'] === 'totp'
                ? TwoFactor::verifyTotp($pending['secret'], $code, "2fa-totp-last:{$admin->id}")
                : TwoFactor::verifyEmailCode(self::setupKey($admin) . ':email', $code);
            if (! $ok) {
                return response()->json([
                    'success' => false,
                    'message' => 'Code incorrect. Vérifiez l’heure de votre téléphone ou demandez un nouveau code.',
                    'errors' => ['two_factor_code' => ['Code incorrect.']],
                ], 422);
            }
            [$recoveryCodes, $hashes] = TwoFactor::generateRecoveryCodes();
            $admin->forceFill([
                'two_factor_method' => $pending['method'],
                'two_factor_secret' => $pending['secret'],
                'two_factor_recovery_codes' => $hashes,
                'two_factor_confirmed_at' => now(),
            ]);
            Cache::forget(self::setupKey($admin));
        }

        $purpose = $admin->access_token_purpose;
        $admin->password = $request->input('password'); // haché via le cast 'hashed'
        $admin->save();
        AdminAccessTokens::consume($admin);
        $actor = ['type' => 'admin', 'id' => $admin->id, 'name' => $admin->name];
        if ($recoveryCodes) {
            Audit::log('two_factor_enabled', 'admin', $admin->id, [$admin->two_factor_method], $admin->cabinet_id, $actor);
        }

        // Réinitialisation : on ferme les sessions ouvertes avec l'ancien mot de passe.
        if ($purpose === AdminAccessTokens::RESET) {
            $admin->tokens()->delete();
        }

        // Session ouverte directement si la 2FA vient d'être réglée, ou si la personne n'en a pas.
        // Sinon (2FA déjà en place), elle se connecte avec son code habituel.
        $cabinet = Cabinet::find($admin->cabinet_id);
        $autoLogin = $admin->is_active && $cabinet?->is_active && ($recoveryCodes || ! $admin->hasTwoFactor());
        $message = $purpose === AdminAccessTokens::INVITE ? 'Votre compte est activé.' : 'Votre mot de passe est modifié.';

        if ($autoLogin) {
            Audit::log('login', 'admin', $admin->id, ['activation'], $admin->cabinet_id, $actor);
            $response = app(AdminAuthController::class)->startSession($admin->fresh(), $message);
            $payload = $response->getData(true);
            $payload['data']['email'] = $admin->email;
            $payload['data']['logged_in'] = true;
            $payload['data']['recovery_codes'] = $recoveryCodes;

            return $response->setData($payload);
        }

        return response()->json([
            'success' => true,
            'message' => $message . ' Vous pouvez maintenant vous connecter.',
            'data' => ['email' => $admin->email, 'logged_in' => false, 'recovery_codes' => null],
        ]);
    }

    private function mustSetUpTwoFactor(Admin $admin): bool
    {
        return ! $admin->hasTwoFactor() && (bool) Cabinet::whereKey($admin->cabinet_id)->value('require_two_factor');
    }

    private static function setupKey(Admin $admin): string
    {
        return "2fa-activation:{$admin->id}";
    }
}
