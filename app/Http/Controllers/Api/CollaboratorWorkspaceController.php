<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DossierDocument;
use App\Models\DossierUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Endpoints exposés au collaborateur connecté (guard `collaborator`).
 * Le collaborateur ne voit QUE les dossiers où il est assigné (dossiers.collaborator_id = me).
 */
class CollaboratorWorkspaceController extends Controller
{
    private function me(Request $request)
    {
        return $request->user('collaborator');
    }

    private function authorizeDossier(Request $request, int $dossierId): Dossier
    {
        $me = $this->me($request);
        if (!$me) {
            abort(401, 'Non authentifié');
        }
        $dossier = Dossier::with(['client', 'familyMember'])->findOrFail($dossierId);
        // Comparaison souple : Eloquent peut renvoyer collaborator_id en string selon le driver.
        abort_unless((int) $dossier->collaborator_id === (int) $me->id, 403, 'Accès refusé à ce dossier');
        // Accès révoqué temporairement par l'admin (sans suppression du compte)
        abort_if($dossier->collab_access_revoked, 403, 'L\'accès à ce dossier a été suspendu par l\'administrateur.');
        return $dossier;
    }

    /** Liste des dossiers attribués au collaborateur connecté, avec compteurs. */
    public function listDossiers(Request $request)
    {
        $me = $this->me($request);
        $dossiers = Dossier::with(['client:id,first_name,last_name,email', 'familyMember:id,first_name,last_name,relationship'])
            ->where('collaborator_id', $me->id)
            ->where('collab_access_revoked', false)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $dossiers->map(function (Dossier $d) {
                $docsTotal = $d->documents()->count();
                $docsDone = $d->documents()->where('status', 'completed')->count();
                $invItemsTotal = \App\Models\InvitationItem::whereHas('invitation', fn($q) => $q->where('dossier_id', $d->id))->count();
                $invItemsDone = \App\Models\InvitationItem::whereHas('invitation', fn($q) => $q->where('dossier_id', $d->id))
                    ->where('status', 'completed')->count();

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'service_name' => $d->service_name,
                    'status' => $d->status,
                    'opened_at' => $d->opened_at?->format('Y-m-d'),
                    'deadline_at' => $d->deadline_at?->format('Y-m-d'),
                    'client' => $d->client ? [
                        'id' => $d->client->id,
                        'name' => trim(($d->client->first_name ?? '') . ' ' . ($d->client->last_name ?? '')),
                        'email' => $d->client->email,
                    ] : null,
                    'family_member' => $d->familyMember ? [
                        'id' => $d->familyMember->id,
                        'name' => trim(($d->familyMember->first_name ?? '') . ' ' . ($d->familyMember->last_name ?? '')),
                        'relationship' => $d->familyMember->relationship,
                    ] : null,
                    'docs_progress' => "{$docsDone}/{$docsTotal}",
                    'invitation_progress' => "{$invItemsDone}/{$invItemsTotal}",
                ];
            }),
        ]);
    }

    /**
     * Détail d'un dossier vu par le collaborateur :
     * - documents de base (éditables)
     * - invitations envoyées au client (read-only avec état)
     * - uploads libres du collaborateur sur ce dossier
     */
    public function showDossier(Request $request, $dossierId)
    {
        $dossier = $this->authorizeDossier($request, (int) $dossierId);
        $dossier->load([
            'documents', 'uploads', 'supplementaryFiles',
            'invitations.items.formType', 'invitations.items.documentTemplate',
            'invitations.uploads',
        ]);

        $invitations = $dossier->invitations->map(function ($inv) {
            return [
                'id' => $inv->id,
                'unique_code' => $inv->unique_code,
                'email' => $inv->email,
                'status' => $inv->status,
                'sent_at' => $inv->sent_at?->format('Y-m-d H:i'),
                'expires_at' => $inv->expires_at?->format('Y-m-d H:i'),
                'items' => $inv->items->map(fn($it) => [
                    'id' => $it->id,
                    'kind' => $it->item_kind,
                    'status' => $it->status,
                    'form_type' => $it->formType ? ['id' => $it->formType->id, 'name' => $it->formType->name, 'code' => $it->formType->code] : null,
                    'document_template' => $it->documentTemplate ? ['id' => $it->documentTemplate->id, 'name' => $it->documentTemplate->name] : null,
                    'has_filled_pdf' => (bool) $it->pdf_filled_path,
                    'last_saved_at' => $it->last_saved_at?->format('Y-m-d H:i'),
                    'completed_at' => $it->completed_at?->format('Y-m-d H:i'),
                ]),
                // Fichiers libres téléversés par le client lors de cette invitation
                'client_uploads' => $inv->uploads->map(fn($u) => [
                    'id' => $u->id,
                    'label' => $u->label,
                    'original_filename' => $u->original_filename,
                    'mime_type' => $u->mime_type,
                    'size' => $u->size,
                    'created_at' => $u->created_at?->format('Y-m-d H:i'),
                ]),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $dossier->id,
                'name' => $dossier->name,
                'service_name' => $dossier->service_name,
                'status' => $dossier->status,
                'opened_at' => $dossier->opened_at?->format('Y-m-d'),
                'deadline_at' => $dossier->deadline_at?->format('Y-m-d'),
                'notes' => $dossier->notes,
                'allow_collab_uploads' => (bool) $dossier->allow_collab_uploads,
                'client' => $dossier->client ? [
                    'id' => $dossier->client->id,
                    'name' => trim(($dossier->client->first_name ?? '') . ' ' . ($dossier->client->last_name ?? '')),
                    'email' => $dossier->client->email,
                ] : null,
                'family_member' => $dossier->familyMember ? [
                    'id' => $dossier->familyMember->id,
                    'name' => trim(($dossier->familyMember->first_name ?? '') . ' ' . ($dossier->familyMember->last_name ?? '')),
                    'relationship' => $dossier->familyMember->relationship,
                ] : null,
                'documents' => $dossier->documents->map(fn($d) => [
                    'id' => $d->id,
                    'doc_type' => $d->doc_type ?: 'ircc',
                    'name' => $d->name,
                    'description' => $d->description,
                    'status' => $d->status,
                    'has_filled_pdf' => (bool) $d->filled_pdf_path,
                    'form_data' => $d->form_data,
                    'last_saved_at' => $d->last_saved_at?->format('Y-m-d H:i'),
                    'completed_at' => $d->completed_at?->format('Y-m-d H:i'),
                ]),
                'supplementary_files' => $dossier->supplementaryFiles->map(fn($f) => [
                    'id' => $f->id,
                    'label' => $f->label,
                    'original_filename' => $f->original_filename,
                    'mime_type' => $f->mime_type,
                    'size' => $f->size,
                    'created_at' => $f->created_at?->format('Y-m-d H:i'),
                ]),
                'uploads' => $dossier->uploads->map(fn($u) => [
                    'id' => $u->id,
                    'label' => $u->label,
                    'original_filename' => $u->original_filename,
                    'mime_type' => $u->mime_type,
                    'size' => $u->size,
                    'created_at' => $u->created_at?->format('Y-m-d H:i'),
                ]),
                'invitations' => $invitations,
            ],
        ]);
    }

    // ─── Document de base : remplissage ─────────────────────────────────────

    public function getDocumentMeta(Request $request, $docId)
    {
        $doc = DossierDocument::with('dossier')->findOrFail($docId);
        $this->authorizeDossier($request, $doc->dossier_id);
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $doc->id,
                'dossier_id' => $doc->dossier_id,
                'name' => $doc->name,
                'description' => $doc->description,
                'status' => $doc->status,
                'has_filled_pdf' => (bool) $doc->filled_pdf_path,
                'form_data' => $doc->form_data,
                'last_saved_at' => $doc->last_saved_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    public function getDocumentPdf(Request $request, $docId)
    {
        $doc = DossierDocument::with('dossier')->findOrFail($docId);
        $this->authorizeDossier($request, $doc->dossier_id);

        // Sert la version remplie si elle existe, sinon le template vierge.
        $path = $doc->filled_pdf_path && Storage::disk('local')->exists($doc->filled_pdf_path)
            ? $doc->filled_pdf_path
            : $doc->template_path;

        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }
        $content = Storage::disk('local')->get($path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $doc->name . '.pdf"',
        ]);
    }

    public function saveDocument(Request $request, $docId)
    {
        $doc = DossierDocument::with('dossier')->findOrFail($docId);
        $this->authorizeDossier($request, $doc->dossier_id);

        $request->validate([
            'pdf_base64' => 'required|string',
            'form_data' => 'nullable|array',
        ]);

        $pdfContent = base64_decode($request->input('pdf_base64'), true);
        if ($pdfContent === false) {
            return response()->json(['success' => false, 'message' => 'PDF invalide'], 422);
        }

        // Supprime l'ancien filled pdf si présent
        if ($doc->filled_pdf_path && Storage::disk('local')->exists($doc->filled_pdf_path)) {
            Storage::disk('local')->delete($doc->filled_pdf_path);
        }

        $filename = "dossier-documents/{$doc->dossier_id}/filled-{$doc->id}-" . time() . '.pdf';
        Storage::disk('local')->put($filename, $pdfContent);

        $doc->filled_pdf_path = $filename;
        $doc->form_data = $request->input('form_data');
        $doc->last_saved_at = now();
        if ($doc->status === 'completed') {
            // Si modifié après completion, on repasse en cours pour signaler
            $doc->status = 'in_progress';
            $doc->completed_at = null;
        }
        $doc->save();

        return response()->json(['success' => true, 'data' => ['last_saved_at' => $doc->last_saved_at->format('Y-m-d H:i')]]);
    }

    public function markComplete(Request $request, $docId)
    {
        $doc = DossierDocument::with('dossier')->findOrFail($docId);
        $this->authorizeDossier($request, $doc->dossier_id);
        $doc->markCompleted();
        return response()->json(['success' => true]);
    }

    // ─── Uploads libres du collaborateur ───────────────────────────────────

    public function uploadFile(Request $request, $dossierId)
    {
        $dossier = $this->authorizeDossier($request, (int) $dossierId);
        if (!$dossier->allow_collab_uploads) {
            return response()->json(['success' => false, 'message' => 'Téléversement non autorisé'], 403);
        }
        $request->validate([
            'file' => 'required|file|max:20480',
            'label' => 'required|string|max:191',
        ]);
        $me = $this->me($request);
        $file = $request->file('file');
        $path = $file->store("dossier-uploads/{$dossier->id}", 'local');

        $upload = DossierUpload::create([
            'dossier_id' => $dossier->id,
            'collaborator_id' => $me->id,
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

    public function deleteUpload(Request $request, $dossierId, $uploadId)
    {
        $dossier = $this->authorizeDossier($request, (int) $dossierId);
        $upload = DossierUpload::where('dossier_id', $dossier->id)->findOrFail($uploadId);

        if ($upload->path && Storage::disk('local')->exists($upload->path)) {
            Storage::disk('local')->delete($upload->path);
        }
        $upload->delete();
        return response()->json(['success' => true]);
    }

    // ─── Invitations (lecture seule pour le collab) ─────────────────────────

    /**
     * Détail d'un item d'invitation (formulaire ou document) en lecture seule pour le collab.
     * Vérifie que l'item appartient à un dossier où le collab est assigné.
     */
    public function getInvitationItem(Request $request, $itemId)
    {
        $me = $this->me($request);
        if (!$me) abort(401, 'Non authentifié');

        $item = \App\Models\InvitationItem::with(['invitation.dossier', 'formType', 'documentTemplate'])
            ->findOrFail($itemId);

        $dossier = $item->invitation?->dossier;
        if (!$dossier || (int) $dossier->collaborator_id !== (int) $me->id) {
            abort(403, 'Accès refusé');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $item->id,
                'kind' => $item->item_kind,
                'status' => $item->status,
                'form_type' => $item->formType ? [
                    'id' => $item->formType->id,
                    'code' => $item->formType->code,
                    'name' => $item->formType->name,
                ] : null,
                'document_template' => $item->documentTemplate ? [
                    'id' => $item->documentTemplate->id,
                    'name' => $item->documentTemplate->name,
                ] : null,
                'form_data' => $item->form_data,
                'has_filled_pdf' => (bool) $item->pdf_filled_path,
                'invitation_id' => $item->invitation_id,
                'dossier_id' => $dossier->id,
                'client_name' => $item->invitation->custom_name
                    ?: ($item->invitation->client
                        ? trim(($item->invitation->client->first_name ?? '') . ' ' . ($item->invitation->client->last_name ?? ''))
                        : null),
                'last_saved_at' => $item->last_saved_at?->format('Y-m-d H:i'),
                'completed_at' => $item->completed_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    /**
     * Sert un fichier librement téléversé par le CLIENT lors d'une invitation
     * (lecture seule pour le collaborateur assigné au dossier).
     */
    public function getInvitationClientUpload(Request $request, $dossierId, $invitationId, $uploadId)
    {
        $dossier = $this->authorizeDossier($request, (int) $dossierId);
        $invitation = \App\Models\Invitation::where('dossier_id', $dossier->id)->findOrFail($invitationId);
        $upload = \App\Models\InvitationUpload::where('invitation_id', $invitation->id)->findOrFail($uploadId);

        if (!$upload->path || !Storage::disk('local')->exists($upload->path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }
        return response()->download(
            Storage::disk('local')->path($upload->path),
            $upload->original_filename,
            ['Content-Type' => $upload->mime_type ?: 'application/octet-stream']
        );
    }

    /**
     * Sert un fichier supplémentaire d'un dossier (lecture/preview ou download).
     */
    public function getSupplementaryFile(Request $request, $dossierId, $fileId)
    {
        $dossier = $this->authorizeDossier($request, (int) $dossierId);
        $file = \App\Models\DossierSupplementaryFile::where('dossier_id', $dossier->id)->findOrFail($fileId);
        if (!$file->path || !Storage::disk('local')->exists($file->path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }
        $absPath = Storage::disk('local')->path($file->path);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        return response()->file($absPath, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $disposition . '; filename="' . $file->original_filename . '"',
        ]);
    }

    public function getInvitationItemPdf(Request $request, $dossierId, $invitationId, $itemId)
    {
        $dossier = $this->authorizeDossier($request, (int) $dossierId);
        $invitation = \App\Models\Invitation::where('dossier_id', $dossier->id)->findOrFail($invitationId);
        $item = \App\Models\InvitationItem::with('documentTemplate')
            ->where('invitation_id', $invitation->id)
            ->findOrFail($itemId);

        // Sert le PDF rempli s'il existe, sinon le template vierge envoyé au client.
        $path = $item->pdf_filled_path && Storage::disk('local')->exists($item->pdf_filled_path)
            ? $item->pdf_filled_path
            : $item->documentTemplate?->pdf_path;

        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'PDF non disponible'], 404);
        }
        $content = Storage::disk('local')->get($path);
        $name = $item->documentTemplate?->name ?: 'document';
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '.pdf"',
        ]);
    }
}
