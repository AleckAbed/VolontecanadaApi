<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DocumentInvitation;
use App\Models\DocumentRequest;
use App\Models\DocumentTemplate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // TEMPLATES — Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function indexTemplates(Request $request)
    {
        $query = DocumentTemplate::with(['creator', 'categoryRel'])
            ->where('is_active', true);

        if ($request->filled('service_name')) {
            $query->where('service_name', $request->input('service_name'));
        }
        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhere('service_name', 'like', $search);
            });
        }

        $templates = $query->orderByDesc('created_at')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'service_name' => $t->service_name,
                'target_location' => $t->target_location,
                'category' => $t->category,
                'category_id' => $t->category_id,
                'category_label' => $t->categoryRel?->name
                    ?? (DocumentTemplate::categoryOptions()[$t->category] ?? $t->category),
                'has_schema' => $t->hasSchema(),
                'created_by' => $t->creator?->name,
                'created_at' => $t->created_at->format('d/m/Y'),
            ]);

        return response()->json(['success' => true, 'data' => $templates]);
    }

    public function storeTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'service_name' => 'nullable|string|max:255',
            'target_location' => 'nullable|in:any,in_canada,outside_canada',
            'category' => 'nullable|in:ircc,cabinet,contrat,autre',
            'is_active' => 'nullable|boolean',
            'pdf' => 'required|file|mimes:pdf|max:20480',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = $request->user('admin');

        $path = $request->file('pdf')->store('document-templates', 'local');

        $template = DocumentTemplate::create([
            'name' => $request->name,
            'description' => $request->description,
            'service_name' => $request->input('service_name'),
            'target_location' => $request->input('target_location', 'any'),
            'category' => $request->input('category', 'autre'),
            'is_active' => $request->boolean('is_active', true),
            'pdf_path' => $path,
            'template_json' => null,
            'created_by' => $admin->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Modèle créé avec succès',
            'data' => ['id' => $template->id],
        ], 201);
    }

    /**
     * Met à jour les métadonnées d'un modèle : nom, description, service.
     * Le PDF source ne change pas via cet endpoint (use storeTemplate + delete pour ça).
     */
    public function updateTemplate(Request $request, int $id)
    {
        $template = DocumentTemplate::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'service_name' => 'nullable|string|max:255',
            'target_location' => 'nullable|in:any,in_canada,outside_canada',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template->fill($request->only(['name', 'description', 'service_name', 'target_location']));
        if ($request->has('is_active')) {
            $template->is_active = $request->boolean('is_active');
        }
        $template->save();

        return response()->json([
            'success' => true,
            'message' => 'Modèle mis à jour',
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'service_name' => $template->service_name,
                'target_location' => $template->target_location,
                'is_active' => $template->is_active,
            ],
        ]);
    }

    public function showTemplate(Request $request, int $id)
    {
        $template = DocumentTemplate::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'service_name' => $template->service_name,
                'target_location' => $template->target_location,
                'category' => $template->category,
                'category_label' => DocumentTemplate::categoryOptions()[$template->category] ?? $template->category,
                'template_json' => $template->template_json,
                'has_schema' => $template->hasSchema(),
                'created_at' => $template->created_at->format('d/m/Y'),
            ],
        ]);
    }

    public function updateTemplateSchema(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'template_json' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template = DocumentTemplate::findOrFail($id);
        $template->update(['template_json' => $request->template_json]);

        return response()->json([
            'success' => true,
            'message' => 'Schéma sauvegardé avec succès',
        ]);
    }

    public function destroyTemplate(int $id)
    {
        $template = DocumentTemplate::findOrFail($id);
        Storage::disk('local')->delete($template->pdf_path);
        $template->delete();

        return response()->json(['success' => true, 'message' => 'Modèle supprimé']);
    }

    /** Sert le fichier PDF original du modèle */
    public function servePdf(Request $request, int $id)
    {
        $template = DocumentTemplate::findOrFail($id);

        if (!Storage::disk('local')->exists($template->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }

        $content = Storage::disk('local')->get($template->pdf_path);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $template->name . '.pdf"',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REQUESTS — Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function indexRequests(Request $request)
    {
        $requests = DocumentRequest::with(['template', 'client', 'dossier', 'sentBy'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'token' => $r->token,
                'template_name' => $r->template?->name,
                'client_name' => $r->client?->full_name,
                'client_email' => $r->client?->email,
                'dossier_name' => $r->dossier?->name,
                'status' => $r->status,
                'status_label' => DocumentRequest::statusOptions()[$r->status] ?? $r->status,
                'is_expired' => $r->isExpired(),
                'sent_at' => $r->sent_at?->format('d/m/Y H:i'),
                'submitted_at' => $r->submitted_at?->format('d/m/Y H:i'),
                'expires_at' => $r->expires_at?->format('d/m/Y'),
                'email_sent' => $r->email_sent,
            ]);

        return response()->json(['success' => true, 'data' => $requests]);
    }

    public function sendRequest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => 'required|exists:document_templates,id',
            'client_id' => 'required|exists:clients,id',
            'dossier_id' => 'nullable|exists:dossiers,id',
            'message' => 'nullable|string|max:1000',
            'expires_days' => 'nullable|integer|min:1|max:90',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = $request->user('admin');
        $template = DocumentTemplate::findOrFail($request->template_id);

        if (!$template->hasSchema()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce modèle n\'a pas encore de champs définis. Veuillez d\'abord configurer les champs.',
            ], 422);
        }

        $token = DocumentRequest::generateToken();
        $days = $request->expires_days ?? 14;

        $docRequest = DocumentRequest::create([
            'template_id' => $request->template_id,
            'client_id' => $request->client_id,
            'dossier_id' => $request->dossier_id,
            'token' => $token,
            'status' => 'pending',
            'message' => $request->message,
            'expires_at' => Carbon::now()->addDays($days),
            'sent_by' => $admin->id,
            'sent_at' => now(),
        ]);

        $docRequest->load('client', 'template');

        $emailSent = false;
        $emailError = null;
        try {
            Mail::to($docRequest->client->email)->send(new DocumentInvitation($docRequest));
            $emailSent = true;
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
        }

        $docRequest->update(['email_sent' => $emailSent, 'email_error' => $emailError]);

        return response()->json([
            'success' => true,
            'message' => $emailSent
                ? 'Document envoyé avec succès par email'
                : 'Document créé mais l\'email n\'a pas pu être envoyé',
            'data' => [
                'id' => $docRequest->id,
                'token' => $token,
                'email_sent' => $emailSent,
                'email_error' => $emailError,
            ],
        ], 201);
    }

    public function showRequest(int $id)
    {
        $docRequest = DocumentRequest::with(['template', 'client', 'dossier', 'sentBy', 'validatedBy'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $docRequest->id,
                'token' => $docRequest->token,
                'template' => [
                    'id' => $docRequest->template?->id,
                    'name' => $docRequest->template?->name,
                    'category' => $docRequest->template?->category,
                ],
                'client' => $docRequest->client ? [
                    'id' => $docRequest->client->id,
                    'full_name' => $docRequest->client->full_name,
                    'email' => $docRequest->client->email,
                ] : null,
                'dossier' => $docRequest->dossier ? [
                    'id' => $docRequest->dossier->id,
                    'name' => $docRequest->dossier->name,
                ] : null,
                'status' => $docRequest->status,
                'status_label' => DocumentRequest::statusOptions()[$docRequest->status],
                'message' => $docRequest->message,
                'form_data' => $docRequest->form_data,
                'has_filled_pdf' => !empty($docRequest->pdf_filled_path),
                'is_expired' => $docRequest->isExpired(),
                'expires_at' => $docRequest->expires_at?->format('d/m/Y'),
                'sent_at' => $docRequest->sent_at?->format('d/m/Y H:i'),
                'submitted_at' => $docRequest->submitted_at?->format('d/m/Y H:i'),
                'validated_at' => $docRequest->validated_at?->format('d/m/Y H:i'),
                'validated_by' => $docRequest->validatedBy?->name,
                'rejection_reason' => $docRequest->rejection_reason,
                'email_sent' => $docRequest->email_sent,
                'sent_by' => $docRequest->sentBy?->name,
            ],
        ]);
    }

    public function validateRequest(Request $request, int $id)
    {
        $admin = $request->user('admin');
        $docRequest = DocumentRequest::findOrFail($id);

        if ($docRequest->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les documents soumis peuvent être validés',
            ], 422);
        }

        $docRequest->update([
            'status' => 'validated',
            'validated_by' => $admin->id,
            'validated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Document validé avec succès']);
    }

    public function rejectRequest(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Veuillez fournir une raison de rejet',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = $request->user('admin');
        $docRequest = DocumentRequest::findOrFail($id);

        if ($docRequest->status !== 'submitted') {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les documents soumis peuvent être rejetés',
            ], 422);
        }

        $docRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'validated_by' => $admin->id,
            'validated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Document rejeté']);
    }

    /** Sert le PDF rempli par le client */
    public function serveFilledPdf(int $id)
    {
        $docRequest = DocumentRequest::with('template')->findOrFail($id);

        if (empty($docRequest->pdf_filled_path) || !Storage::disk('local')->exists($docRequest->pdf_filled_path)) {
            return response()->json(['success' => false, 'message' => 'PDF non disponible'], 404);
        }

        $content = Storage::disk('local')->get($docRequest->pdf_filled_path);
        $name = $docRequest->template?->name ?? 'document';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '-rempli.pdf"',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REMPLISSAGE — Public (client via token)
    // ─────────────────────────────────────────────────────────────────────────

    /** Récupère les infos du document à remplir */
    public function getDocumentByToken(string $token)
    {
        $docRequest = DocumentRequest::with(['template', 'client'])->where('token', $token)->firstOrFail();

        if ($docRequest->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Ce lien a expiré', 'code' => 'expired'], 410);
        }

        if (in_array($docRequest->status, ['validated', 'rejected'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce document a déjà été traité',
                'code' => 'closed',
                'status' => $docRequest->status,
            ], 410);
        }

        $template = $docRequest->template;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $docRequest->id,
                'status' => $docRequest->status,
                'message' => $docRequest->message,
                'client_name' => $docRequest->client?->full_name,
                'template_name' => $template?->name,
                'template_json' => $template?->template_json,
                'form_data' => $docRequest->form_data, // données sauvegardées (brouillon)
                'expires_at' => $docRequest->expires_at?->format('d/m/Y'),
            ],
        ]);
    }

    /** Sert le PDF de base du modèle au client (via token) */
    public function serveBasePdfByToken(string $token)
    {
        $docRequest = DocumentRequest::with('template')->where('token', $token)->firstOrFail();

        if ($docRequest->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Lien expiré'], 410);
        }

        $template = $docRequest->template;

        if (!Storage::disk('local')->exists($template->pdf_path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }

        $content = Storage::disk('local')->get($template->pdf_path);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="document.pdf"',
        ]);
    }

    /** Sauvegarde le brouillon (sans soumettre) */
    public function saveProgress(Request $request, string $token)
    {
        $docRequest = DocumentRequest::where('token', $token)->firstOrFail();

        if ($docRequest->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Lien expiré'], 410);
        }

        $validator = Validator::make($request->all(), [
            'form_data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $docRequest->update([
            'form_data' => $request->form_data,
            'status' => 'in_progress',
        ]);

        return response()->json(['success' => true, 'message' => 'Progression sauvegardée']);
    }

    /** Soumission finale avec le PDF généré */
    public function submitDocument(Request $request, string $token)
    {
        $docRequest = DocumentRequest::where('token', $token)->firstOrFail();

        if ($docRequest->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Lien expiré'], 410);
        }

        if ($docRequest->status === 'submitted') {
            return response()->json(['success' => false, 'message' => 'Document déjà soumis'], 422);
        }

        $validator = Validator::make($request->all(), [
            'form_data' => 'required|array',
            'pdf_base64' => 'required|string', // PDF généré côté client (pdfme generator)
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        // Décoder et stocker le PDF final
        $pdfContent = base64_decode($request->pdf_base64);
        $pdfPath = 'document-filled/' . $docRequest->id . '_' . time() . '.pdf';
        Storage::disk('local')->put($pdfPath, $pdfContent);

        $docRequest->update([
            'form_data' => $request->form_data,
            'pdf_filled_path' => $pdfPath,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Document soumis avec succès. Le cabinet sera notifié.',
        ]);
    }
}
