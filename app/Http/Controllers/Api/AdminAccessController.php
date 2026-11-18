<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AdminPasswordResetMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\AdminAccessTokens;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Liens d'accès du personnel (routes publiques, limitées en débit) :
 *  - mot de passe oublié ;
 *  - vérification d'un lien (invitation ou réinitialisation) ;
 *  - choix du mot de passe via ce lien.
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
            ],
        ]);
    }

    /** Applique le nouveau mot de passe et invalide le lien. */
    public function complete(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => AdminAccessTokens::passwordRules(),
        ], AdminAccessTokens::passwordMessages());

        $admin = AdminAccessTokens::find($request->input('token'));
        if (! $admin) {
            return response()->json([
                'success' => false,
                'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau.',
            ], 410);
        }

        $purpose = $admin->access_token_purpose;
        $admin->password = $request->input('password'); // haché via le cast 'hashed'
        $admin->save();
        AdminAccessTokens::consume($admin);

        // Réinitialisation : on ferme les sessions ouvertes avec l'ancien mot de passe.
        if ($purpose === AdminAccessTokens::RESET) {
            $admin->tokens()->delete();
        }

        return response()->json([
            'success' => true,
            'message' => $purpose === AdminAccessTokens::INVITE
                ? 'Votre compte est activé. Vous pouvez maintenant vous connecter.'
                : 'Votre mot de passe est modifié. Vous pouvez maintenant vous connecter.',
            'data' => ['email' => $admin->email],
        ]);
    }
}
