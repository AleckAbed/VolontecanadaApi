<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\Client;
use App\Models\DocumentTemplate;
use App\Models\Dossier;
use App\Models\FamilyMember;
use App\Models\FormType;
use App\Models\Invitation;
use App\Models\InvitationItem;
use App\Models\InvitationUpload;
use App\Models\QuestionnaireRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class InvitationController extends Controller
{
    // ─── ADMIN ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = Invitation::with(['client', 'familyMember', 'dossier', 'sentBy', 'items'])
            ->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        $page = $query->paginate($request->integer('per_page', 15));
        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function show($id)
    {
        $invitation = Invitation::with([
            'client', 'familyMember', 'dossier', 'sentBy',
            'items.formType.category',
            'items.documentTemplate.categoryRel',
            'uploads',
        ])->findOrFail($id);

        // Pre-fetch linked questionnaire form_data for form items
        $linkedCodes = $invitation->items
            ->where('item_kind', 'form')
            ->pluck('linked_questionnaire_code')
            ->filter()
            ->values()
            ->all();
        $qrData = [];
        if (!empty($linkedCodes)) {
            $qrData = QuestionnaireRequest::whereIn('unique_code', $linkedCodes)
                ->get(['unique_code', 'form_data', 'status', 'completed_at'])
                ->keyBy('unique_code');
        }

        $payload = $this->formatAdmin($invitation);
        // Inject linked QR form_data into form items
        $payload['items'] = collect($payload['items'])->map(function ($item) use ($invitation, $qrData) {
            if ($item['kind'] === 'form') {
                $rawItem = $invitation->items->firstWhere('id', $item['id']);
                $code = $rawItem?->linked_questionnaire_code;
                if ($code && isset($qrData[$code])) {
                    $item['form_data'] = $qrData[$code]->form_data;
                    if ($qrData[$code]->status === 'completed') {
                        $item['status'] = 'completed';
                    } elseif ($qrData[$code]->status === 'in_progress') {
                        $item['status'] = 'in_progress';
                    }
                }
            }
            return $item;
        })->values()->toArray();

        return response()->json(['success' => true, 'data' => $payload]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'client_type' => 'required|in:existing,custom',
            'client_id' => 'required_if:client_type,existing|nullable|exists:clients,id',
            'family_member_id' => 'nullable|exists:family_members,id',
            'dossier_id' => 'nullable|exists:dossiers,id',
            'custom_name' => 'required_if:client_type,custom|nullable|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:32',
            'message' => 'nullable|string',
            'allow_uploads' => 'nullable|boolean',
            'expires_days' => 'nullable|integer|min:1|max:365',
            'items' => 'required|array|min:1',
            'items.*.kind' => 'required|in:form,document',
            'items.*.form_type_id' => 'nullable|exists:form_types,id',
            'items.*.document_template_id' => 'nullable|exists:document_templates,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $admin = $request->user('admin');

        // Resolve recipient: family member > existing client > custom
        $recipientEmail = $request->email;
        $recipientPhone = $request->client_type === 'custom' ? $request->phone : null;
        $member = null;

        if ($request->client_type === 'existing') {
            $client = Client::findOrFail($request->client_id);

            if ($request->filled('family_member_id')) {
                $member = FamilyMember::where('id', $request->family_member_id)
                    ->where('client_id', $client->id)
                    ->firstOrFail();
                $recipientEmail = $member->email ?: $client->email;
                $recipientPhone = $member->phone;
            } else {
                $recipientEmail = $client->email;
            }
        }

        // Validate dossier belongs to the principal client (when both supplied)
        if ($request->filled('dossier_id') && $request->client_type === 'existing') {
            $dossier = Dossier::where('id', $request->dossier_id)
                ->where('client_id', $request->client_id)
                ->first();
            if (!$dossier) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le dossier sélectionné n\'appartient pas à ce client',
                ], 422);
            }
        }

        $invitation = DB::transaction(function () use ($request, $admin, $recipientEmail, $recipientPhone, $member) {
            $invitation = Invitation::create([
                'unique_code' => Invitation::generateUniqueCode(),
                'client_type' => $request->client_type,
                'client_id' => $request->client_type === 'existing' ? $request->client_id : null,
                'family_member_id' => $member?->id,
                'dossier_id' => $request->client_type === 'existing' ? $request->dossier_id : null,
                'custom_name' => $request->client_type === 'custom' ? $request->custom_name : null,
                'email' => $recipientEmail,
                'phone' => $recipientPhone,
                'message' => $request->message,
                'allow_uploads' => $request->boolean('allow_uploads'),
                'status' => 'pending',
                'sent_at' => now(),
                'expires_at' => Carbon::now()->addDays($request->integer('expires_days', 14)),
                'sent_by' => $admin->id,
            ]);

            foreach ($request->items as $idx => $item) {
                $kind = $item['kind'];
                if ($kind === 'form' && empty($item['form_type_id'])) continue;
                if ($kind === 'document' && empty($item['document_template_id'])) continue;

                $linkedCode = null;

                // For form items, also create a backing QuestionnaireRequest
                // so the existing legacy form pages can be reused.
                if ($kind === 'form') {
                    $formType = FormType::find($item['form_type_id']);
                    if ($formType) {
                        $linkedCode = QuestionnaireRequest::generateUniqueCode();
                        $qr = QuestionnaireRequest::create([
                            'unique_code' => $linkedCode,
                            'client_type' => $invitation->client_type,
                            'client_id' => $invitation->client_id,
                            'custom_name' => $invitation->custom_name,
                            'email' => $invitation->email,
                            'phone' => $invitation->phone,
                            'form_type' => $formType->code,
                            'status' => 'pending',
                            'sent_at' => $invitation->sent_at,
                            'expires_at' => $invitation->expires_at,
                            'sent_by' => $invitation->sent_by,
                            'email_sent' => true, // we don't send a separate email
                        ]);
                        // Will set invitation_item_id after the item is created below
                    }
                }

                $invItem = InvitationItem::create([
                    'invitation_id' => $invitation->id,
                    'item_kind' => $kind,
                    'form_type_id' => $kind === 'form' ? $item['form_type_id'] : null,
                    'document_template_id' => $kind === 'document' ? $item['document_template_id'] : null,
                    'linked_questionnaire_code' => $linkedCode,
                    'status' => 'pending',
                    'sort_order' => $idx,
                ]);

                if (!empty($linkedCode)) {
                    QuestionnaireRequest::where('unique_code', $linkedCode)
                        ->update(['invitation_item_id' => $invItem->id]);
                }
            }

            return $invitation;
        });

        // Send email
        $emailSent = false;
        $emailError = null;
        try {
            Mail::to($invitation->email)->send(new InvitationMail($invitation));
            $emailSent = true;
        } catch (\Exception $e) {
            $emailError = $e->getMessage();
            Log::error('Erreur envoi invitation', ['id' => $invitation->id, 'error' => $emailError]);
        }
        $invitation->update(['email_sent' => $emailSent, 'email_error' => $emailError]);

        return response()->json([
            'success' => true,
            'data' => $this->formatAdmin($invitation->fresh(['items', 'client', 'uploads'])),
            'email_sent' => $emailSent,
        ], 201);
    }

    public function destroy($id)
    {
        $invitation = Invitation::findOrFail($id);
        $invitation->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Resend the invitation email for an existing invitation.
     * Updates email_sent / email_error and bumps sent_at.
     */
    public function resendEmail($id)
    {
        $invitation = Invitation::findOrFail($id);

        if (empty($invitation->email)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune adresse courriel enregistrée pour cette invitation.',
            ], 422);
        }

        try {
            Mail::to($invitation->email)->send(new InvitationMail($invitation));
            $invitation->update([
                'email_sent' => true,
                'email_error' => null,
                'sent_at' => now(),
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Email renvoyé avec succès.',
                'email_sent' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur renvoi invitation', ['id' => $invitation->id, 'error' => $e->getMessage()]);
            $invitation->update([
                'email_sent' => false,
                'email_error' => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'email_sent' => false,
            ], 500);
        }
    }

    /**
     * Returns the filled PDF for an item — admin viewing.
     * Falls back to the original template if no filled version exists.
     */
    public function adminItemPdf($invitationId, $itemId)
    {
        $item = InvitationItem::where('invitation_id', $invitationId)->findOrFail($itemId);
        if ($item->item_kind !== 'document') abort(404);
        if ($item->pdf_filled_path && Storage::disk('local')->exists($item->pdf_filled_path)) {
            return response()->file(Storage::disk('local')->path($item->pdf_filled_path));
        }
        if ($item->documentTemplate && Storage::disk('local')->exists($item->documentTemplate->pdf_path)) {
            return response()->file(Storage::disk('local')->path($item->documentTemplate->pdf_path));
        }
        abort(404);
    }

    /**
     * Télécharge un fichier complémentaire téléversé par le client — admin.
     */
    public function adminDownloadUpload($invitationId, $uploadId)
    {
        $upload = InvitationUpload::where('invitation_id', $invitationId)->findOrFail($uploadId);
        if (!Storage::disk('local')->exists($upload->path)) {
            abort(404);
        }
        return response()->download(
            Storage::disk('local')->path($upload->path),
            $upload->original_filename
        );
    }

    // ─── CLIENT (PUBLIC) ────────────────────────────────────────────────────

    /** Verify access via email + unique_code combo (legacy-style security check). */
    public function publicVerifyAccess(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'code' => 'required|string|size:32',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Erreur de validation', 'errors' => $validator->errors()], 422);
        }

        $invitation = Invitation::where('email', $request->email)
            ->where('unique_code', $request->code)
            ->first();

        if (!$invitation) {
            return response()->json(['success' => false, 'message' => 'Email ou code invalide'], 404);
        }

        if ($invitation->isExpired()) {
            $invitation->update(['status' => 'expired']);
            return response()->json([
                'success' => false,
                'message' => 'L\'invitation a expiré',
                'status' => 'expired',
            ], 410);
        }

        if ($invitation->isCompleted()) {
            return response()->json([
                'success' => false,
                'message' => 'L\'invitation a déjà été soumise',
                'status' => 'completed',
            ], 200);
        }

        // Mark in_progress on first access
        if ($invitation->status === 'pending') {
            $invitation->update(['status' => 'in_progress']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Accès autorisé',
            'data' => [
                'unique_code' => $invitation->unique_code,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function publicShow($code)
    {
        $invitation = Invitation::with([
            'items.formType.category',
            'items.documentTemplate.categoryRel',
            'client',
            'uploads',
        ])->where('unique_code', $code)->first();

        if (!$invitation) {
            return response()->json(['success' => false, 'message' => 'Invitation introuvable'], 404);
        }
        if ($invitation->isExpired()) {
            return response()->json(['success' => false, 'message' => 'Cette invitation a expiré', 'status' => 'expired'], 410);
        }

        return response()->json(['success' => true, 'data' => $this->formatPublic($invitation)]);
    }

    public function publicGetItemPdf($code, $itemId)
    {
        $invitation = Invitation::where('unique_code', $code)->firstOrFail();
        if ($invitation->isExpired()) abort(410);

        $item = InvitationItem::where('invitation_id', $invitation->id)->findOrFail($itemId);
        if ($item->item_kind !== 'document') abort(404);

        // If the client has already saved a filled version, serve it (resume).
        if ($item->pdf_filled_path && Storage::disk('local')->exists($item->pdf_filled_path)) {
            return response()->file(Storage::disk('local')->path($item->pdf_filled_path));
        }
        // Otherwise serve the original template.
        if ($item->documentTemplate && Storage::disk('local')->exists($item->documentTemplate->pdf_path)) {
            return response()->file(Storage::disk('local')->path($item->documentTemplate->pdf_path));
        }
        abort(404);
    }

    public function publicSaveFormItem(Request $request, $code, $itemId)
    {
        $invitation = Invitation::where('unique_code', $code)->firstOrFail();
        if (!$invitation->isAccessible()) {
            return response()->json(['success' => false, 'message' => 'Invitation non accessible'], 410);
        }
        $item = InvitationItem::where('invitation_id', $invitation->id)->findOrFail($itemId);
        if ($item->item_kind !== 'form') {
            return response()->json(['success' => false, 'message' => 'Item invalide'], 422);
        }
        // Verrouillage : un item déjà soumis ne peut plus être modifié par le client.
        // Pour modifier, contacter le cabinet (qui peut réinitialiser côté admin).
        if ($item->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Ce formulaire a déjà été soumis. Pour le modifier, contactez votre conseiller.',
                'locked' => true,
            ], 423); // 423 Locked
        }

        $request->validate(['form_data' => 'required|array']);

        $item->form_data = $request->form_data;
        $item->markStarted();
        $invitation->recomputeStatus();

        return response()->json(['success' => true]);
    }

    public function publicSaveDocumentItem(Request $request, $code, $itemId)
    {
        $invitation = Invitation::where('unique_code', $code)->firstOrFail();
        if (!$invitation->isAccessible()) {
            return response()->json(['success' => false, 'message' => 'Invitation non accessible'], 410);
        }
        $item = InvitationItem::where('invitation_id', $invitation->id)->findOrFail($itemId);
        if ($item->item_kind !== 'document') {
            return response()->json(['success' => false, 'message' => 'Item invalide'], 422);
        }
        if ($item->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Ce document a déjà été soumis. Pour le modifier, contactez votre conseiller.',
                'locked' => true,
            ], 423);
        }

        $request->validate([
            'pdf_base64' => 'nullable|string',
            'form_data' => 'nullable|array',
        ]);

        if (!$request->filled('pdf_base64') && !$request->filled('form_data')) {
            return response()->json(['success' => false, 'message' => 'Rien à sauvegarder'], 422);
        }

        // Persist form values (annotation storage) — drives form restoration
        if ($request->has('form_data')) {
            $item->form_data = $request->form_data ?? [];
        }

        // Persist filled PDF bytes if provided
        if ($request->filled('pdf_base64')) {
            $bytes = base64_decode($request->pdf_base64, true);
            if ($bytes === false) {
                return response()->json(['success' => false, 'message' => 'PDF invalide'], 422);
            }
            $path = "invitation-items/{$invitation->unique_code}/item-{$item->id}.pdf";
            Storage::disk('local')->put($path, $bytes);
            $item->pdf_filled_path = $path;

            // Propagation vers le DossierDocument partagé : si l'invitation est liée
            // à un dossier et que ce modèle de document a été instancié dans ce dossier,
            // on sauvegarde aussi dans le filled_pdf_path du DossierDocument pour que
            // collaborateur et admin voient la même version.
            if ($invitation->dossier_id && $item->document_template_id) {
                $dossierDoc = \App\Models\DossierDocument::where('dossier_id', $invitation->dossier_id)
                    ->where('document_template_id', $item->document_template_id)
                    ->first();
                if ($dossierDoc) {
                    // Supprime l'ancien fichier rempli si différent
                    if ($dossierDoc->filled_pdf_path
                        && $dossierDoc->filled_pdf_path !== $path
                        && Storage::disk('local')->exists($dossierDoc->filled_pdf_path)) {
                        Storage::disk('local')->delete($dossierDoc->filled_pdf_path);
                    }
                    $sharedPath = "dossier-documents/{$dossierDoc->dossier_id}/filled-{$dossierDoc->id}-" . time() . '.pdf';
                    Storage::disk('local')->put($sharedPath, $bytes);
                    $dossierDoc->filled_pdf_path = $sharedPath;
                    $dossierDoc->filled_by = 'client';
                    $dossierDoc->last_saved_at = now();
                    // Pas de changement automatique de status (le client n'est pas censé marquer terminé pour le collab)
                    $dossierDoc->save();
                }
            }
        }

        $item->markStarted();
        $invitation->recomputeStatus();

        return response()->json(['success' => true]);
    }

    public function publicCompleteItem(Request $request, $code, $itemId)
    {
        $invitation = Invitation::where('unique_code', $code)->firstOrFail();
        if (!$invitation->isAccessible()) {
            return response()->json(['success' => false, 'message' => 'Invitation non accessible'], 410);
        }
        $item = InvitationItem::where('invitation_id', $invitation->id)->findOrFail($itemId);
        $item->markCompleted();
        $invitation->recomputeStatus();

        return response()->json(['success' => true]);
    }

    public function publicSubmitAll($code)
    {
        $invitation = Invitation::with('items')->where('unique_code', $code)->firstOrFail();
        if (!$invitation->isAccessible()) {
            return response()->json(['success' => false, 'message' => 'Invitation non accessible'], 410);
        }

        // All items must be at least in_progress with content; mark them completed
        foreach ($invitation->items as $item) {
            if ($item->status !== 'completed') {
                $hasContent = ($item->item_kind === 'form' && !empty($item->form_data))
                    || ($item->item_kind === 'document' && !empty($item->pdf_filled_path));
                if (!$hasContent) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tous les éléments doivent être remplis avant de soumettre',
                        'incomplete_item_id' => $item->id,
                    ], 422);
                }
                $item->markCompleted();
            }
        }
        $invitation->status = 'completed';
        $invitation->completed_at = now();
        $invitation->save();

        return response()->json(['success' => true]);
    }

    /**
     * Le client téléverse un document complémentaire libre (avec libellé).
     * Multipart : champ 'file' + 'label'. Tout type, max 20 Mo.
     */
    public function publicUploadFile(Request $request, $code)
    {
        $invitation = Invitation::where('unique_code', $code)->firstOrFail();
        if (!$invitation->isAccessible()) {
            return response()->json(['success' => false, 'message' => 'Invitation non accessible'], 410);
        }
        if (!$invitation->allow_uploads) {
            return response()->json(['success' => false, 'message' => 'Le téléversement n\'est pas autorisé pour cette invitation'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:20480', // 20 Mo, tout type
            'label' => 'required|string|max:191',
        ]);

        $file = $request->file('file');
        $path = $file->store("invitation-uploads/{$invitation->unique_code}", 'local');

        $upload = InvitationUpload::create([
            'invitation_id' => $invitation->id,
            'label' => $request->input('label'),
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $upload->id,
                'label' => $upload->label,
                'original_filename' => $upload->original_filename,
                'mime_type' => $upload->mime_type,
                'size' => $upload->size,
                'created_at' => $upload->created_at?->format('Y-m-d H:i'),
            ],
        ], 201);
    }

    /**
     * Le client supprime un de ses documents complémentaires.
     */
    public function publicDeleteUpload($code, $uploadId)
    {
        $invitation = Invitation::where('unique_code', $code)->firstOrFail();
        if (!$invitation->isAccessible()) {
            return response()->json(['success' => false, 'message' => 'Invitation non accessible'], 410);
        }
        $upload = InvitationUpload::where('invitation_id', $invitation->id)->findOrFail($uploadId);

        if ($upload->path && Storage::disk('local')->exists($upload->path)) {
            Storage::disk('local')->delete($upload->path);
        }
        $upload->delete();

        return response()->json(['success' => true]);
    }

    // ─── HELPERS ────────────────────────────────────────────────────────────

    private function formatAdmin(Invitation $inv): array
    {
        return [
            'id' => $inv->id,
            'unique_code' => $inv->unique_code,
            'client_type' => $inv->client_type,
            'client' => $inv->client ? [
                'id' => $inv->client->id,
                'name' => trim(($inv->client->first_name ?? '') . ' ' . ($inv->client->last_name ?? '')),
                'email' => $inv->client->email,
            ] : null,
            'family_member' => $inv->familyMember ? [
                'id' => $inv->familyMember->id,
                'name' => trim(($inv->familyMember->first_name ?? '') . ' ' . ($inv->familyMember->last_name ?? '')),
                'relationship' => $inv->familyMember->relationship,
                'email' => $inv->familyMember->email,
            ] : null,
            'dossier' => $inv->dossier ? [
                'id' => $inv->dossier->id,
                'name' => $inv->dossier->name,
                'status' => $inv->dossier->status,
            ] : null,
            'custom_name' => $inv->custom_name,
            'email' => $inv->email,
            'phone' => $inv->phone,
            'message' => $inv->message,
            'allow_uploads' => (bool) $inv->allow_uploads,
            'status' => $inv->status,
            'sent_at' => $inv->sent_at?->format('Y-m-d H:i'),
            'expires_at' => $inv->expires_at?->format('Y-m-d H:i'),
            'completed_at' => $inv->completed_at?->format('Y-m-d H:i'),
            'sent_by' => $inv->sentBy?->name,
            'email_sent' => $inv->email_sent,
            'uploads' => $inv->uploads->map(fn($u) => [
                'id' => $u->id,
                'label' => $u->label,
                'original_filename' => $u->original_filename,
                'mime_type' => $u->mime_type,
                'size' => $u->size,
                'created_at' => $u->created_at?->format('Y-m-d H:i'),
            ])->values(),
            'items' => $inv->items->map(fn($i) => [
                'id' => $i->id,
                'kind' => $i->item_kind,
                'status' => $i->status,
                'form_type' => $i->formType ? [
                    'id' => $i->formType->id,
                    'code' => $i->formType->code,
                    'name' => $i->formType->name,
                    'category' => $i->formType->category?->name,
                ] : null,
                'document_template' => $i->documentTemplate ? [
                    'id' => $i->documentTemplate->id,
                    'name' => $i->documentTemplate->name,
                    'category' => $i->documentTemplate->categoryRel?->name ?? $i->documentTemplate->category,
                ] : null,
                'has_filled_pdf' => !empty($i->pdf_filled_path),
                'form_data' => $i->form_data,
                'last_saved_at' => $i->last_saved_at?->format('Y-m-d H:i'),
                'completed_at' => $i->completed_at?->format('Y-m-d H:i'),
            ]),
        ];
    }

    private function formatPublic(Invitation $inv): array
    {
        // Sync each form item's status from its linked QuestionnaireRequest
        $linkedCodes = $inv->items
            ->where('item_kind', 'form')
            ->pluck('linked_questionnaire_code')
            ->filter()
            ->values()
            ->all();

        $linkedQrs = [];
        if (!empty($linkedCodes)) {
            $linkedQrs = QuestionnaireRequest::whereIn('unique_code', $linkedCodes)
                ->get()
                ->keyBy('unique_code');
        }

        return [
            'unique_code' => $inv->unique_code,
            'client_name' => $inv->custom_name
                ?: ($inv->client ? trim(($inv->client->first_name ?? '') . ' ' . ($inv->client->last_name ?? '')) : null),
            'email' => $inv->email,
            'message' => $inv->message,
            'allow_uploads' => (bool) $inv->allow_uploads,
            'uploads' => $inv->uploads->map(fn($u) => [
                'id' => $u->id,
                'label' => $u->label,
                'original_filename' => $u->original_filename,
                'mime_type' => $u->mime_type,
                'size' => $u->size,
                'created_at' => $u->created_at?->format('Y-m-d H:i'),
            ])->values(),
            'status' => $inv->status,
            'expires_at' => $inv->expires_at?->format('Y-m-d H:i'),
            'is_expired' => $inv->isExpired(),
            'items' => $inv->items->map(function ($i) use ($linkedQrs) {
                // For form items, derive status from the linked questionnaire
                $effectiveStatus = $i->status;
                $linkedQr = null;
                if ($i->item_kind === 'form' && $i->linked_questionnaire_code) {
                    $linkedQr = $linkedQrs[$i->linked_questionnaire_code] ?? null;
                    if ($linkedQr) {
                        if ($linkedQr->status === 'completed') $effectiveStatus = 'completed';
                        elseif ($linkedQr->status === 'in_progress') $effectiveStatus = 'in_progress';
                    }
                }

                return [
                    'id' => $i->id,
                    'kind' => $i->item_kind,
                    'status' => $effectiveStatus,
                    'name' => $i->item_kind === 'form'
                        ? ($i->formType?->name ?? 'Formulaire')
                        : ($i->documentTemplate?->name ?? 'Document'),
                    'description' => $i->item_kind === 'form'
                        ? $i->formType?->description
                        : $i->documentTemplate?->description,
                    'category' => $i->item_kind === 'form'
                        ? $i->formType?->category?->name
                        : ($i->documentTemplate?->categoryRel?->name ?? $i->documentTemplate?->category),
                    'form_type_code' => $i->formType?->code,
                    'document_template_id' => $i->documentTemplate?->id,
                    'linked_questionnaire_code' => $i->linked_questionnaire_code,
                    'form_data' => $i->form_data,
                    'has_filled_pdf' => !empty($i->pdf_filled_path),
                    'last_saved_at' => $i->last_saved_at?->format('Y-m-d H:i'),
                ];
            }),
        ];
    }
}
