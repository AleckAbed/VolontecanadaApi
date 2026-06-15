<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ClientController extends Controller
{
    /**
     * Liste des clients (admin).
     */
    public function index(Request $request)
    {
        try {
            $query = Client::query()->orderBy('created_at', 'desc');

            if ($request->has('client_type')) {
                $query->where('client_type', $request->client_type);
            }
            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%");
                });
            }

            $clients = $query->withCount(['familyMembers', 'dossiers'])->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'data' => $clients,
            ]);
        } catch (\Throwable $e) {
            \Log::error('ClientController@index: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $message = config('app.debug') ? $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() : 'Erreur serveur lors du chargement des clients.';
            return response()->json([
                'success' => false,
                'message' => $message,
            ], 500);
        }
    }

    /**
     * Créer un client (optionnellement avec membres de famille).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_type' => 'required|in:single,family',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:clients,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'country_of_residence' => 'nullable|string|max:100',
            'in_canada' => 'nullable|boolean',
            'passport_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'family_members' => 'nullable|array',
            'family_members.*.first_name' => 'required_with:family_members|string|max:255',
            'family_members.*.last_name' => 'required_with:family_members|string|max:255',
            'family_members.*.relationship' => 'required_with:family_members|string|max:50',
            'family_members.*.date_of_birth' => 'nullable|date',
            'family_members.*.nationality' => 'nullable|string|max:100',
            'family_members.*.country_of_residence' => 'nullable|string|max:100',
            'family_members.*.address' => 'nullable|string',
            'family_members.*.passport_number' => 'nullable|string|max:100',
            'family_members.*.phone' => 'nullable|string|max:50',
            'family_members.*.email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only([
            'client_type', 'first_name', 'last_name', 'email', 'phone',
            'date_of_birth', 'nationality', 'country_of_residence', 'passport_number', 'address',
        ]);
        $data['password'] = Hash::make($request->password);
        $data['client_type'] = $request->client_type;
        if ($request->has('in_canada')) {
            $data['in_canada'] = $request->boolean('in_canada');
        }

        $client = Client::create($data);

        if ($request->client_type === 'family' && $request->has('family_members') && is_array($request->family_members)) {
            foreach ($request->family_members as $fm) {
                $client->familyMembers()->create([
                    'first_name' => $fm['first_name'],
                    'last_name' => $fm['last_name'],
                    'relationship' => $fm['relationship'],
                    'date_of_birth' => $fm['date_of_birth'] ?? null,
                    'nationality' => $fm['nationality'] ?? null,
                    'country_of_residence' => $fm['country_of_residence'] ?? null,
                    'address' => $fm['address'] ?? null,
                    'passport_number' => $fm['passport_number'] ?? null,
                    'phone' => $fm['phone'] ?? null,
                    'email' => $fm['email'] ?? null,
                ]);
            }
        }

        $client->load('familyMembers');

        return response()->json([
            'success' => true,
            'message' => 'Client créé avec succès',
            'data' => $client,
        ], 201);
    }

    /**
     * Détail d'un client (avec membres et dossiers).
     */
    public function show(int $id)
    {
        $client = Client::with(['familyMembers', 'dossiers' => fn ($q) => $q->with('familyMember')])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $client,
        ]);
    }

    /**
     * Mettre à jour un client.
     */
    public function update(Request $request, int $id)
    {
        $client = Client::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'client_type' => 'sometimes|in:single,family',
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:clients,email,' . $id,
            'password' => 'nullable|string|min:8',
            'phone' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'country_of_residence' => 'nullable|string|max:100',
            'in_canada' => 'nullable|boolean',
            'passport_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only([
            'client_type', 'first_name', 'last_name', 'email', 'phone',
            'date_of_birth', 'nationality', 'country_of_residence', 'passport_number', 'address', 'is_active',
        ]);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }
        if ($request->has('in_canada')) {
            $data['in_canada'] = $request->boolean('in_canada');
        }
        $client->update($data);

        $client->load('familyMembers', 'dossiers');

        return response()->json([
            'success' => true,
            'message' => 'Client mis à jour',
            'data' => $client,
        ]);
    }

    /**
     * Supprimer un client.
     */
    public function destroy(int $id)
    {
        $client = Client::findOrFail($id);
        $client->delete();

        return response()->json([
            'success' => true,
            'message' => 'Client supprimé',
        ]);
    }

    /**
     * Ajouter un membre de famille à un client.
     */
    public function addFamilyMember(Request $request, int $clientId)
    {
        $client = Client::findOrFail($clientId);

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'relationship' => 'required|string|max:50',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'country_of_residence' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'passport_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $member = $client->familyMembers()->create($request->only([
            'first_name', 'last_name', 'relationship', 'date_of_birth',
            'nationality', 'country_of_residence', 'address', 'passport_number', 'phone', 'email',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Membre ajouté',
            'data' => $member,
        ], 201);
    }

    /**
     * Mettre à jour un membre de famille.
     */
    public function updateFamilyMember(Request $request, int $clientId, int $memberId)
    {
        $member = FamilyMember::where('client_id', $clientId)->findOrFail($memberId);

        $validator = Validator::make($request->all(), [
            'first_name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'relationship' => 'sometimes|string|max:50',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:100',
            'country_of_residence' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'passport_number' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $member->update($request->only([
            'first_name', 'last_name', 'relationship', 'date_of_birth',
            'nationality', 'country_of_residence', 'address', 'passport_number', 'phone', 'email',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Membre mis à jour',
            'data' => $member,
        ]);
    }

    /**
     * Supprimer un membre de famille.
     */
    public function destroyFamilyMember(int $clientId, int $memberId)
    {
        $member = FamilyMember::where('client_id', $clientId)->findOrFail($memberId);
        $member->delete();

        return response()->json([
            'success' => true,
            'message' => 'Membre supprimé',
        ]);
    }

    /**
     * Options pour les listes déroulantes (relations, etc.).
     */
    public function options()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'client_type' => ['single' => 'Client unique', 'family' => 'Famille'],
                'relationship' => FamilyMember::relationshipOptions(),
            ],
        ]);
    }
}
