<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ClientAuthController extends Controller
{
    /**
     * Login client
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            // Un même courriel peut être client de plusieurs cabinets : on retient
            // le compte dont le mot de passe correspond.
            $client = Client::acrossCabinets()->where('email', $request->email)->get()
                ->first(fn ($c) => $c->password && Hash::check($request->password, $c->password));

            if (!$client) {
                throw ValidationException::withMessages([
                    'email' => ['Les informations d\'identification sont incorrectes.'],
                ]);
            }

            if (!$client->is_active) {
                throw ValidationException::withMessages([
                    'email' => ['Votre compte est désactivé. Contactez l\'administration.'],
                ]);
            }

            $token = $client->createToken('client-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Connexion réussie',
                'data' => [
                    'client' => [
                        'id' => $client->id,
                        'first_name' => $client->first_name,
                        'last_name' => $client->last_name,
                        'email' => $client->email,
                        'full_name' => $client->full_name,
                    ],
                    'token' => $token,
                ],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'La base de données est injoignable. Démarrez MySQL/MariaDB, puis vérifiez DB_* dans api/.env.',
            ], 503);
        }
    }

    /**
     * Logout client
     */
    public function logout(Request $request)
    {
        $request->user('client')->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie',
        ]);
    }

    /**
     * Obtenir les informations du client connecté
     */
    public function me(Request $request)
    {
        $client = $request->user('client');

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'full_name' => $client->full_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'date_of_birth' => $client->date_of_birth,
                'nationality' => $client->nationality,
                'passport_number' => $client->passport_number,
                'is_active' => $client->is_active,
                'created_at' => $client->created_at,
            ],
        ]);
    }
}


