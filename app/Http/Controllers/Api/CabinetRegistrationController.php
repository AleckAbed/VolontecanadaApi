<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\CabinetProvisioner;
use App\Support\AdminAccessTokens;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;

/**
 * Inscription d'un nouveau cabinet sur la plateforme (route publique).
 * Le propriétaire est connecté immédiatement après la création.
 */
class CabinetRegistrationController extends Controller
{
    public const PROVINCES = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

    public function register(Request $request, CabinetProvisioner $provisioner, AdminAuthController $auth)
    {
        $data = $request->validate([
            'cabinet_name' => 'required|string|max:150',
            'province' => 'nullable|string|in:' . implode(',', self::PROVINCES),
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => AdminAccessTokens::passwordRules(),
            'accept_terms' => 'accepted',
        ], AdminAccessTokens::passwordMessages() + [
            'accept_terms.accepted' => 'Vous devez accepter les conditions d\'utilisation.',
        ]);

        // Le courriel d'un membre du personnel est unique sur toute la plateforme.
        $emailTaken = CurrentCabinet::withoutScope(fn () => Admin::acrossCabinets()->where('email', $data['email'])->exists());
        if ($emailTaken) {
            return response()->json([
                'success' => false,
                'message' => 'Un compte existe déjà avec ce courriel. Connectez-vous ou utilisez « Mot de passe oublié ».',
                'errors' => ['email' => ['Un compte existe déjà avec ce courriel.']],
            ], 422);
        }

        [$cabinet, $owner] = $provisioner->create(
            $data['cabinet_name'],
            $data['province'] ?? null,
            $data['name'],
            $data['email'],
            $data['password'],
        );

        return $auth->startSession($owner, "Bienvenue ! Le cabinet « {$cabinet->name} » est créé.", 201);
    }
}
