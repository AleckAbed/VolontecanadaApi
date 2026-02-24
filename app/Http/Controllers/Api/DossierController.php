<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DossierController extends Controller
{
    /**
     * Liste des dossiers (admin). Filtre optionnel par client_id.
     */
    public function index(Request $request)
    {
        $query = Dossier::with(['client:id,first_name,last_name,email,client_type', 'familyMember:id,first_name,last_name,relationship'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $dossiers = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $dossiers,
        ]);
    }

    /**
     * Créer un dossier d'immigration.
     * Si client a une famille: scope peut être client | member | family; si member, family_member_id requis.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|exists:clients,id',
            'scope' => 'required|in:client,member,family',
            'family_member_id' => 'required_if:scope,member|nullable|exists:family_members,id',
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|max:50',
            'opened_at' => 'nullable|date',
            'deadline_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $client = Client::findOrFail($request->client_id);

        if ($request->scope === 'client' && $client->client_type === 'family') {
            // ok: dossier for principal only
        }
        if ($request->scope === 'member') {
            $member = $client->familyMembers()->find($request->family_member_id);
            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le membre doit appartenir au client choisi.',
                    'errors' => ['family_member_id' => ['Membre invalide pour ce client.']],
                ], 422);
            }
        }
        if ($request->scope === 'family' && $client->client_type !== 'family') {
            return response()->json([
                'success' => false,
                'message' => 'Le client n\'est pas une famille. Choisissez scope "client".',
                'errors' => ['scope' => ['Invalide pour un client unique.']],
            ], 422);
        }

        $data = $request->only([
            'client_id', 'scope', 'family_member_id', 'name', 'status',
            'opened_at', 'deadline_at', 'notes',
        ]);
        $data['family_member_id'] = $request->scope === 'member' ? $request->family_member_id : null;
        $data['status'] = $data['status'] ?? 'en_cours';

        $dossier = Dossier::create($data);
        $dossier->load(['client', 'familyMember']);

        return response()->json([
            'success' => true,
            'message' => 'Dossier créé',
            'data' => $dossier,
        ], 201);
    }

    /**
     * Détail d'un dossier.
     */
    public function show(int $id)
    {
        $dossier = Dossier::with(['client', 'familyMember'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $dossier,
        ]);
    }

    /**
     * Mettre à jour un dossier.
     */
    public function update(Request $request, int $id)
    {
        $dossier = Dossier::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'scope' => 'sometimes|in:client,member,family',
            'family_member_id' => 'nullable|exists:family_members,id',
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|max:50',
            'opened_at' => 'nullable|date',
            'deadline_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->only(['scope', 'family_member_id', 'name', 'status', 'opened_at', 'deadline_at', 'notes']);
        if (isset($data['scope']) && $data['scope'] !== 'member') {
            $data['family_member_id'] = null;
        }
        $dossier->update($data);
        $dossier->load(['client', 'familyMember']);

        return response()->json([
            'success' => true,
            'message' => 'Dossier mis à jour',
            'data' => $dossier,
        ]);
    }

    /**
     * Supprimer un dossier.
     */
    public function destroy(int $id)
    {
        $dossier = Dossier::findOrFail($id);
        $dossier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dossier supprimé',
        ]);
    }

    /**
     * Options pour listes déroulantes (scope, status).
     */
    public function options()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'scope' => Dossier::scopeOptions(),
                'status' => Dossier::statusOptions(),
            ],
        ]);
    }
}
