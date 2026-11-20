<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CabinetRequestMail;
use App\Models\CabinetRequest;
use App\Models\PlatformAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Demande d'ouverture d'un cabinet (route publique, limitée en débit), appelée par
 * le formulaire de l'application ou par le futur site vitrine.
 * Réponse identique dans tous les cas : on ne révèle pas si le courriel a déjà un compte.
 */
class CabinetRequestController extends Controller
{
    public const TEAM_SIZES = ['1', '2-5', '6-15', '16+'];

    public function store(Request $request)
    {
        $data = $request->validate([
            'cabinet_name' => 'required|string|max:150',
            'province' => 'nullable|string|in:' . implode(',', CabinetRegistrationController::PROVINCES),
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:40',
            'team_size' => 'nullable|string|in:' . implode(',', self::TEAM_SIZES),
            'license_number' => 'nullable|string|max:40',
            'message' => 'nullable|string|max:2000',
            'accept_terms' => 'accepted',
            // Piège à robots : champ invisible qu'un humain laisse vide.
            'website' => 'nullable|max:0',
        ], [
            'accept_terms.accepted' => 'Vous devez accepter les conditions d\'utilisation et la politique de confidentialité.',
            'website.max' => 'Demande refusée.',
        ]);

        $email = mb_strtolower(trim($data['email']));
        $alreadyPending = CabinetRequest::where('email', $email)->where('status', CabinetRequest::PENDING)->exists();

        if (! $alreadyPending) {
            $req = CabinetRequest::create([
                'cabinet_name' => trim($data['cabinet_name']),
                'province' => $data['province'] ?? null,
                'contact_name' => trim($data['name']),
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'team_size' => $data['team_size'] ?? null,
                'license_number' => $data['license_number'] ?? null,
                'message' => $data['message'] ?? null,
                'consent_at' => now(),
                'ip' => $request->ip(),
            ]);

            Mail::to($email)->send(new CabinetRequestMail($req, CabinetRequestMail::RECEIVED));
            foreach (PlatformAdmin::query()->pluck('email') as $platformEmail) {
                Mail::to($platformEmail)->send(new CabinetRequestMail($req, CabinetRequestMail::NEW));
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Merci ! Votre demande est envoyée. Vous recevrez une réponse par courriel, généralement sous 2 jours ouvrables.',
        ], 202);
    }
}
