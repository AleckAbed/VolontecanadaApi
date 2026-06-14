<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionnaireRequest;
use App\Models\Client;
use App\Models\Admin;
use App\Mail\QuestionnaireInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class QuestionnaireController extends Controller
{
    /**
     * Envoyer un formulaire à un client (Admin seulement)
     */
    public function sendQuestionnaire(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_type' => 'required|in:existing,custom',
            'client_id' => 'required_if:client_type,existing|exists:clients,id',
            'custom_name' => 'required_if:client_type,custom|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'required_if:client_type,custom|string|max:20',
            'form_type' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = $request->user('admin');
        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        // Récupérer l'email selon le type de client
        $email = $request->email;
        if ($request->client_type === 'existing') {
            $client = Client::findOrFail($request->client_id);
            $email = $client->email;
        }

        // Générer un code unique
        $uniqueCode = QuestionnaireRequest::generateUniqueCode();

        // Créer la demande de formulaire
        $questionnaireRequest = QuestionnaireRequest::create([
            'unique_code' => $uniqueCode,
            'client_type' => $request->client_type,
            'client_id' => $request->client_type === 'existing' ? $request->client_id : null,
            'custom_name' => $request->client_type === 'custom' ? $request->custom_name : null,
            'email' => $email,
            'phone' => $request->client_type === 'custom' ? $request->phone : null,
            'form_type' => $request->form_type,
            'status' => 'pending',
            'sent_at' => now(),
            'expires_at' => Carbon::now()->addDays(14),
            'sent_by' => $admin->id,
        ]);

        // Envoyer l'email avec le lien et le code
        $emailSent = false;
        $emailError = null;
        try {
            Mail::to($email)->send(new QuestionnaireInvitation($questionnaireRequest));
            $emailSent = true;
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            \Log::error('Erreur lors de l\'envoi de l\'email questionnaire', [
                'questionnaire_id' => $questionnaireRequest->id,
                'email' => $email,
                'error' => $emailError,
            ]);
        }

        $questionnaireRequest->update([
            'email_sent' => $emailSent,
            'email_error' => $emailError,
        ]);

        $message = $emailSent
            ? 'Formulaire envoyé avec succès. Un email a été envoyé au client.'
            : 'Formulaire créé mais l\'email n\'a pas pu être envoyé au client. Vérifiez la configuration SMTP et les logs (storage/logs/laravel.log).';

        return response()->json([
            'success' => true,
            'message' => $message,
            'email_sent' => $emailSent,
            'email_error' => $emailError,
            'data' => [
                'id' => $questionnaireRequest->id,
                'unique_code' => $uniqueCode,
                'email' => $email,
                'expires_at' => $questionnaireRequest->expires_at->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }

    /**
     * Vérifier l'accès au formulaire (Email + Code)
     */
    public function verifyAccess(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:32',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $questionnaireRequest = QuestionnaireRequest::where('email', $request->email)
            ->where('unique_code', $request->code)
            ->first();

        if (!$questionnaireRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Email ou code invalide',
            ], 404);
        }

        // Vérifier l'expiration
        if ($questionnaireRequest->isExpired()) {
            $questionnaireRequest->update(['status' => 'expired']);
            return response()->json([
                'success' => false,
                'message' => 'Le formulaire a expiré',
                'status' => 'expired',
            ], 410);
        }

        // Vérifier si déjà complété
        if ($questionnaireRequest->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Le formulaire a déjà été complété',
                'status' => 'completed',
            ], 200);
        }

        // Mettre à jour le statut si nécessaire
        if ($questionnaireRequest->status === 'pending') {
            $questionnaireRequest->update(['status' => 'in_progress']);
        }

        // S'assurer que form_data est toujours un tableau (au cas où le cast n'a pas été appliqué)
        $formData = $questionnaireRequest->form_data;
        if (!is_array($formData)) {
            $raw = $questionnaireRequest->getRawOriginal('form_data');
            $formData = is_string($raw) ? (json_decode($raw, true) ?? []) : [];
        }

        return response()->json([
            'success' => true,
            'message' => 'Accès autorisé',
            'data' => [
                'id' => $questionnaireRequest->id,
                'unique_code' => $questionnaireRequest->unique_code,
                'form_type' => $questionnaireRequest->form_type,
                'status' => $questionnaireRequest->status,
                'form_data' => $formData,
                'expires_at' => $questionnaireRequest->expires_at ? $questionnaireRequest->expires_at->format('Y-m-d H:i:s') : null,
                'days_remaining' => $questionnaireRequest->expires_at ? Carbon::now()->diffInDays($questionnaireRequest->expires_at, false) : 14,
            ],
        ], 200);
    }

    /**
     * Sauvegarder les données du formulaire
     */
    public function saveFormData(Request $request, $code)
    {
        // Lire form_data depuis le body JSON (priorité au contenu brut pour éviter les problèmes de parsing)
        $formData = null;
        $rawBody = $request->getContent();
        if (!empty($rawBody) && is_string($rawBody)) {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded) && array_key_exists('form_data', $decoded)) {
                $formData = $decoded['form_data'];
            }
        }
        if ($formData === null) {
            $formData = $request->input('form_data');
        }
        if ($formData === null) {
            $formData = $request->json('form_data');
        }
        // Si reçu en chaîne (double encodage), décoder
        if (is_string($formData)) {
            $formData = json_decode($formData, true);
        }
        if (!is_array($formData)) {
            \Log::warning('Sauvegarde formulaire: form_data manquant ou invalide', [
                'code' => $code,
                'type' => $formData === null ? 'null' : gettype($formData),
                'content_type' => $request->header('Content-Type'),
                'body_length' => strlen($rawBody ?? ''),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Données du formulaire manquantes ou invalides (form_data requis, tableau attendu)',
                'errors' => ['form_data' => ['Le champ form_data doit être un tableau.']],
            ], 422);
        }

        $questionnaireRequest = QuestionnaireRequest::where('unique_code', $code)->first();

        if (!$questionnaireRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Formulaire introuvable',
            ], 404);
        }

        if ($questionnaireRequest->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Le formulaire a expiré',
            ], 410);
        }

        // Fusionner avec les données existantes (ne pas écraser tout si le front envoie un sous-ensemble ou un objet vide)
        $existing = $questionnaireRequest->form_data;
        if (!is_array($existing)) {
            $existing = is_string($existing) ? (json_decode($existing, true) ?? []) : [];
        }
        $merged = array_merge($existing, $formData);

        \Log::info('Sauvegarde des données du formulaire', [
            'code' => $code,
            'incoming_keys' => array_keys($formData),
            'incoming_count' => count($formData),
            'merged_count' => count($merged),
        ]);

        $jsonToStore = json_encode($merged, JSON_UNESCAPED_UNICODE);
        if ($jsonToStore === false) {
            \Log::error('Sauvegarde formulaire: échec encodage JSON', ['code' => $code]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement des données',
            ], 500);
        }

        $updated = \DB::table('questionnaire_requests')
            ->where('unique_code', $code)
            ->update([
                'form_data' => $jsonToStore,
                'status' => 'in_progress',
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            \Log::error('Sauvegarde formulaire: aucune ligne mise à jour', ['code' => $code]);
            return response()->json([
                'success' => false,
                'message' => 'Aucune ligne mise à jour en base',
            ], 500);
        }

        $questionnaireRequest->refresh();

        // Sync linked invitation_item to in_progress
        if ($questionnaireRequest->invitation_item_id) {
            $item = \App\Models\InvitationItem::find($questionnaireRequest->invitation_item_id);
            if ($item && $item->status !== 'completed') {
                $item->markStarted();
                $item->invitation?->recomputeStatus();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Données sauvegardées',
            'data' => [
                'saved_fields' => count($formData),
                'total_fields' => count($merged),
            ],
        ], 200);
    }

    /**
     * Soumettre le formulaire (marquer comme complété)
     */
    public function submitForm(Request $request, $code)
    {
        $validator = Validator::make($request->all(), [
            'form_data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $questionnaireRequest = QuestionnaireRequest::where('unique_code', $code)->first();

        if (!$questionnaireRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Formulaire introuvable',
            ], 404);
        }

        if ($questionnaireRequest->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Le formulaire a expiré',
            ], 410);
        }

        if ($questionnaireRequest->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'Le formulaire a déjà été complété',
            ], 400);
        }

        $questionnaireRequest->update([
            'form_data' => $request->form_data,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // If this questionnaire belongs to an invitation, mark the invitation item completed
        if ($questionnaireRequest->invitation_item_id) {
            $item = \App\Models\InvitationItem::find($questionnaireRequest->invitation_item_id);
            if ($item) {
                $item->markCompleted();
                $item->invitation?->recomputeStatus();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Formulaire soumis avec succès',
            'invitation_code' => $questionnaireRequest->invitation_item_id
                ? \App\Models\InvitationItem::find($questionnaireRequest->invitation_item_id)?->invitation?->unique_code
                : null,
        ], 200);
    }

    /**
     * Lister les formulaires envoyés (Admin)
     */
    public function listQuestionnaires(Request $request)
    {
        $admin = $request->user('admin');
        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        $questionnaires = QuestionnaireRequest::with(['client', 'sentBy'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $questionnaires,
        ], 200);
    }

    /**
     * Obtenir les détails d'un formulaire (Admin)
     */
    public function getQuestionnaire($id)
    {
        $questionnaire = QuestionnaireRequest::with(['client', 'sentBy'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $questionnaire,
        ], 200);
    }

    /**
     * Obtenir la liste des clients (Admin)
     */
    public function getClients(Request $request)
    {
        $admin = $request->user('admin');
        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé',
            ], 401);
        }

        $clients = Client::where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $clients,
        ], 200);
    }
}

