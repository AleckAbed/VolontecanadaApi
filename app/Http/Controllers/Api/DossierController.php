<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CollaboratorDossierAssignedMail;
use App\Models\Collaborator;
use App\Models\Dossier;
use App\Models\Client;
use App\Models\DocumentTemplate;
use App\Models\DossierDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DossierController extends Controller
{
    /**
     * Liste des dossiers (admin). Filtre optionnel par client_id.
     */
    public function index(Request $request)
    {
        $query = Dossier::with([
                'client:id,first_name,last_name,email,client_type',
                'familyMember:id,first_name,last_name,relationship',
                'collaborator:id,first_name,last_name,email',
            ])
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
            'collaborator_id' => 'nullable|exists:collaborators,id',
            'allow_collab_uploads' => 'nullable|boolean',
            'send_base_docs_to_client' => 'nullable|boolean',
            'name' => 'required|string|max:255',
            'service_name' => 'nullable|string|max:255',
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
            'client_id', 'scope', 'family_member_id', 'collaborator_id', 'name', 'service_name', 'status',
            'opened_at', 'deadline_at', 'notes',
        ]);
        $data['family_member_id'] = $request->scope === 'member' ? $request->family_member_id : null;
        $data['status'] = $data['status'] ?? 'en_cours';
        $data['allow_collab_uploads'] = $request->boolean('allow_collab_uploads', true);
        $data['send_base_docs_to_client'] = $request->boolean('send_base_docs_to_client', false);

        $dossier = Dossier::create($data);

        // Auto-attache les modèles de documents rattachés au service choisi
        // comme documents de base du dossier (snapshot du PDF).
        Log::info('Dossier créé — service_name', [
            'dossier_id' => $dossier->id,
            'service_name' => $data['service_name'] ?? null,
            'raw_request_service_name' => $request->input('service_name'),
        ]);
        if (!empty($data['service_name'])) {
            $this->attachServiceTemplates($dossier, $data['service_name']);
        } else {
            Log::warning('Pas de service_name → pas d\'auto-attach', ['dossier_id' => $dossier->id]);
        }

        // Notifie le collaborateur s'il a été assigné dès la création
        if (!empty($data['collaborator_id'])) {
            $this->notifyCollaboratorAssigned($dossier, $data['collaborator_id']);
        }

        $dossier->load(['client', 'familyMember', 'collaborator']);

        return response()->json([
            'success' => true,
            'message' => 'Dossier créé',
            'data' => $dossier,
        ], 201);
    }

    /**
     * Envoie un email au collaborateur pour l'avertir qu'un dossier vient de lui être assigné.
     * Non bloquant : si le mail échoue, le dossier reste créé/mis à jour.
     */
    private function notifyCollaboratorAssigned(Dossier $dossier, int $collaboratorId): void
    {
        try {
            $collab = Collaborator::find($collaboratorId);
            if (!$collab || !$collab->is_active || !$collab->email) return;
            $dossier->loadMissing('client');
            Mail::to($collab->email)->send(new CollaboratorDossierAssignedMail($collab, $dossier));
        } catch (\Throwable $e) {
            Log::warning('Échec notification collaborateur', [
                'dossier_id' => $dossier->id,
                'collab_id' => $collaboratorId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Copie chaque DocumentTemplate associé au service en un DossierDocument
     * (snapshot — une modif ultérieure du modèle ne propage pas).
     */
    private function attachServiceTemplates(Dossier $dossier, string $serviceName): void
    {
        // Filtre par localisation du client (in_canada / outside_canada).
        // Si le client n'a pas renseigné sa position, on accepte 'any' uniquement.
        $dossier->loadMissing('client');
        $clientInCanada = $dossier->client?->in_canada;
        $allowedLocations = ['any'];
        if ($clientInCanada === true) {
            $allowedLocations[] = 'in_canada';
        } elseif ($clientInCanada === false) {
            $allowedLocations[] = 'outside_canada';
        }

        $templates = DocumentTemplate::where('is_active', true)
            ->where('service_name', $serviceName)
            ->whereIn('target_location', $allowedLocations)
            ->get();

        // Templates déjà rattachés (par leur template_id) — évite les doublons
        // lors d'un re-create ou d'une édition qui change le service.
        $alreadyAttached = $dossier->documents()
            ->whereNotNull('document_template_id')
            ->pluck('document_template_id')
            ->all();

        Log::info('Auto-attach résultats', [
            'dossier_id' => $dossier->id,
            'service_name' => $serviceName,
            'templates_found' => $templates->count(),
            'already_attached' => $alreadyAttached,
        ]);

        foreach ($templates as $tpl) {
            if (in_array($tpl->id, $alreadyAttached, true)) continue;
            if (!$tpl->pdf_path || !Storage::disk('local')->exists($tpl->pdf_path)) {
                continue;
            }
            $extension = pathinfo($tpl->pdf_path, PATHINFO_EXTENSION) ?: 'pdf';
            $newPath = "dossier-documents/{$dossier->id}/" . uniqid('auto_') . '.' . $extension;
            Storage::disk('local')->copy($tpl->pdf_path, $newPath);

            DossierDocument::create([
                'dossier_id' => $dossier->id,
                'document_template_id' => $tpl->id,
                'name' => $tpl->name,
                'description' => $tpl->description,
                'template_path' => $newPath,
                'status' => 'in_progress',
                'sort_order' => 0,
            ]);
        }
    }

    /**
     * Détail d'un dossier.
     */
    public function show(int $id)
    {
        $dossier = Dossier::with([
            'client',
            'familyMember',
            'collaborator',
            'documents',
            'uploads',
            'invitations:id,dossier_id,email,status,sent_at,expires_at,completed_at,unique_code',
        ])->findOrFail($id);

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
            'collaborator_id' => 'nullable|exists:collaborators,id',
            'allow_collab_uploads' => 'nullable|boolean',
            'send_base_docs_to_client' => 'nullable|boolean',
            'name' => 'sometimes|string|max:255',
            'service_name' => 'nullable|string|max:255',
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

        $data = $request->only([
            'scope', 'family_member_id', 'collaborator_id', 'name', 'service_name', 'status',
            'opened_at', 'deadline_at', 'notes',
        ]);
        if (isset($data['scope']) && $data['scope'] !== 'member') {
            $data['family_member_id'] = null;
        }
        if ($request->has('allow_collab_uploads')) {
            $data['allow_collab_uploads'] = $request->boolean('allow_collab_uploads');
        }
        if ($request->has('send_base_docs_to_client')) {
            $data['send_base_docs_to_client'] = $request->boolean('send_base_docs_to_client');
        }

        $previousCollabId = $dossier->collaborator_id;
        $previousServiceName = $dossier->service_name;
        $dossier->update($data);

        // Notifie si un (nouveau) collab a été assigné lors de cette mise à jour.
        $newCollabId = $dossier->collaborator_id;
        if ($newCollabId && (int) $newCollabId !== (int) $previousCollabId) {
            $this->notifyCollaboratorAssigned($dossier, (int) $newCollabId);
        }

        // Auto-attache les modèles du nouveau service si le service vient d'être défini/changé.
        if (!empty($dossier->service_name) && $dossier->service_name !== $previousServiceName) {
            $this->attachServiceTemplates($dossier, $dossier->service_name);
        }

        $dossier->load(['client', 'familyMember', 'collaborator']);

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
