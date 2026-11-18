<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ClientPortalAccessMail;
use App\Models\Cabinet;
use App\Models\Client;
use App\Support\AdminAccessTokens;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Accès des clients à leur portail :
 *  - le cabinet invite un client à créer son mot de passe (admin) ;
 *  - mot de passe oublié, vérification et choix du mot de passe (public).
 */
class ClientAccessController extends Controller
{
    /** Admin : envoie au client un lien pour activer son accès au portail. */
    public function invite(int $id)
    {
        $client = Client::findOrFail($id);

        if (! $client->email) {
            return response()->json(['success' => false, 'message' => 'Ce client n\'a pas de courriel.'], 422);
        }

        $purpose = $client->password ? AdminAccessTokens::RESET : AdminAccessTokens::INVITE;
        $sent = $this->send($client, $purpose);

        return response()->json([
            'success' => $sent,
            'message' => $sent
                ? "Lien d'accès au portail envoyé à {$client->email}."
                : 'Le courriel n\'a pas pu être envoyé. Vérifiez la configuration d\'envoi.',
        ], $sent ? 200 : 500);
    }

    /**
     * Mot de passe oublié. Un même courriel peut être client de plusieurs
     * cabinets : chaque compte actif reçoit son lien. Réponse identique que le
     * compte existe ou non.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $clients = CurrentCabinet::withoutScope(
            fn () => Client::acrossCabinets()->where('email', $request->input('email'))->where('is_active', true)->get()
        );

        foreach ($clients as $client) {
            $cabinet = Cabinet::find($client->cabinet_id);
            if ($cabinet && $cabinet->is_active) {
                $this->send($client, $client->password ? AdminAccessTokens::RESET : AdminAccessTokens::INVITE);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Si un compte existe pour ce courriel, un lien vient d\'être envoyé.',
        ]);
    }

    public function check(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        $client = AdminAccessTokens::find($request->input('token'), Client::class);

        if (! $client) {
            return response()->json(['success' => false, 'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau.'], 410);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'purpose' => $client->access_token_purpose,
                'name' => trim("{$client->first_name} {$client->last_name}"),
                'email' => $client->email,
                'cabinet_name' => Cabinet::find($client->cabinet_id)?->name,
            ],
        ]);
    }

    public function complete(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => AdminAccessTokens::passwordRules(),
        ], AdminAccessTokens::passwordMessages());

        $client = AdminAccessTokens::find($request->input('token'), Client::class);
        if (! $client) {
            return response()->json(['success' => false, 'message' => 'Ce lien est invalide ou a expiré. Demandez-en un nouveau.'], 410);
        }

        $client->password = $request->input('password');
        $client->is_active = true;
        $client->save();
        AdminAccessTokens::consume($client);
        $client->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Votre accès est prêt. Vous pouvez maintenant vous connecter à votre portail.',
            'data' => ['email' => $client->email],
        ]);
    }

    private function send(Client $client, string $purpose): bool
    {
        $token = AdminAccessTokens::issue($client, $purpose);

        try {
            Mail::to($client->email)->send(new ClientPortalAccessMail($client, Cabinet::find($client->cabinet_id), $token, $purpose));

            return true;
        } catch (\Throwable $e) {
            Log::error('Erreur envoi accès portail client', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
