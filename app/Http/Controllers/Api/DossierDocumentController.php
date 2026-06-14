<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DocumentTemplate;
use App\Models\DossierDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Documents de base attachés à un dossier.
 * - Admin : CRUD complet + téléversement PDF template + consultation du résultat.
 * - Collaborateur : remplissage + marquage terminé (via CollaboratorWorkspaceController).
 */
class DossierDocumentController extends Controller
{
    // ─── Admin ──────────────────────────────────────────────────────────────

    public function indexForDossier($dossierId)
    {
        $dossier = Dossier::findOrFail($dossierId);
        $docs = $dossier->documents()->get();
        return response()->json([
            'success' => true,
            'data' => $docs->map(fn($d) => $this->format($d)),
        ]);
    }

    public function store(Request $request, $dossierId)
    {
        $dossier = Dossier::findOrFail($dossierId);
        $request->validate([
            'name' => 'nullable|string|max:191',
            'description' => 'nullable|string|max:2000',
            'document_template_id' => 'nullable|exists:document_templates,id',
            'pdf' => 'nullable|file|mimes:pdf|max:20480',
            'sort_order' => 'nullable|integer',
        ]);

        $templateId = $request->input('document_template_id');
        if (!$templateId && !$request->hasFile('pdf')) {
            return response()->json(['success' => false, 'message' => 'Choisissez un modèle ou téléversez un PDF'], 422);
        }

        $name = $request->input('name');
        $templateModel = null;

        if ($templateId) {
            $templateModel = DocumentTemplate::findOrFail($templateId);
            // Copie le PDF du template dans le dossier (snapshot)
            $extension = pathinfo($templateModel->pdf_path, PATHINFO_EXTENSION) ?: 'pdf';
            $newPath = "dossier-documents/{$dossier->id}/" . uniqid('tmpl_') . '.' . $extension;
            if (!Storage::disk('local')->exists($templateModel->pdf_path)) {
                return response()->json(['success' => false, 'message' => 'Fichier du modèle introuvable'], 404);
            }
            Storage::disk('local')->copy($templateModel->pdf_path, $newPath);
            $path = $newPath;
            if (!$name || !trim($name)) $name = $templateModel->name;
        } else {
            $path = $request->file('pdf')->store("dossier-documents/{$dossier->id}", 'local');
            if (!$name || !trim($name)) {
                return response()->json(['success' => false, 'message' => 'Nom requis pour un téléversement libre'], 422);
            }
        }

        $doc = DossierDocument::create([
            'dossier_id' => $dossier->id,
            'document_template_id' => $templateModel?->id,
            'name' => $name,
            'description' => $request->input('description'),
            'template_path' => $path,
            'sort_order' => (int) $request->input('sort_order', 0),
            'status' => 'in_progress',
        ]);

        return response()->json(['success' => true, 'data' => $this->format($doc)], 201);
    }

    public function show($id)
    {
        $doc = DossierDocument::findOrFail($id);
        return response()->json(['success' => true, 'data' => $this->format($doc)]);
    }

    public function update(Request $request, $id)
    {
        $doc = DossierDocument::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer',
        ]);
        $doc->update($data);
        return response()->json(['success' => true, 'data' => $this->format($doc->fresh())]);
    }

    public function destroy($id)
    {
        $doc = DossierDocument::findOrFail($id);
        if ($doc->template_path && Storage::disk('local')->exists($doc->template_path)) {
            Storage::disk('local')->delete($doc->template_path);
        }
        if ($doc->filled_pdf_path && Storage::disk('local')->exists($doc->filled_pdf_path)) {
            Storage::disk('local')->delete($doc->filled_pdf_path);
        }
        $doc->delete();
        return response()->json(['success' => true]);
    }

    /** Sert le PDF template (admin). */
    public function serveTemplate($id)
    {
        $doc = DossierDocument::findOrFail($id);
        return $this->servePdfFile($doc->template_path, $doc->name . '.pdf');
    }

    /** Sert le PDF rempli (admin). */
    public function serveFilled($id)
    {
        $doc = DossierDocument::findOrFail($id);
        if (!$doc->filled_pdf_path) {
            return response()->json(['success' => false, 'message' => 'Aucun PDF rempli disponible'], 404);
        }
        return $this->servePdfFile($doc->filled_pdf_path, $doc->name . ' (rempli).pdf');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function servePdfFile(?string $path, string $downloadName)
    {
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }
        $content = Storage::disk('local')->get($path);
        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $downloadName . '"',
        ]);
    }

    public function format(DossierDocument $d): array
    {
        return [
            'id' => $d->id,
            'dossier_id' => $d->dossier_id,
            'document_template_id' => $d->document_template_id,
            'name' => $d->name,
            'description' => $d->description,
            'status' => $d->status,
            'has_filled_pdf' => (bool) $d->filled_pdf_path,
            'sort_order' => $d->sort_order,
            'last_saved_at' => $d->last_saved_at?->format('Y-m-d H:i'),
            'completed_at' => $d->completed_at?->format('Y-m-d H:i'),
            'created_at' => $d->created_at?->format('Y-m-d H:i'),
        ];
    }
}
