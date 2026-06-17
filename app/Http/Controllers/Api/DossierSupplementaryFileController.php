<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dossier;
use App\Models\DossierSupplementaryFile;
use App\Models\DossierDocument;
use App\Models\DossierUpload;
use App\Models\InvitationUpload;
use App\Models\InvitationItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class DossierSupplementaryFileController extends Controller
{
    // ─── CRUD admin (mode admin) ────────────────────────────────────────────

    public function index($dossierId)
    {
        $dossier = Dossier::findOrFail($dossierId);
        return response()->json([
            'success' => true,
            'data' => $dossier->supplementaryFiles->map(fn($f) => $this->format($f)),
        ]);
    }

    public function store(Request $request, $dossierId)
    {
        $dossier = Dossier::findOrFail($dossierId);
        $request->validate([
            'label' => 'required|string|max:191',
            'file' => 'required|file|max:51200', // 50 Mo, tout type
        ]);
        $file = $request->file('file');
        $path = $file->store("dossier-supplementary/{$dossier->id}", 'local');
        $rec = DossierSupplementaryFile::create([
            'dossier_id' => $dossier->id,
            'label' => $request->input('label'),
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
        ]);
        return response()->json(['success' => true, 'data' => $this->format($rec)], 201);
    }

    public function destroy($id)
    {
        $f = DossierSupplementaryFile::findOrFail($id);
        if ($f->path && Storage::disk('local')->exists($f->path)) {
            Storage::disk('local')->delete($f->path);
        }
        $f->delete();
        return response()->json(['success' => true]);
    }

    /** Sert le fichier (preview ou download selon Content-Disposition demandé). */
    public function show(Request $request, $id)
    {
        $f = DossierSupplementaryFile::findOrFail($id);
        return $this->servePath($f->path, $f->original_filename, $f->mime_type, $request->boolean('download'));
    }

    // ─── Export ZIP — combine plusieurs catégories de fichiers ──────────────

    /**
     * Body : { dossier_id, items: [{ kind: 'ircc'|'fo'|'supplementary'|'client_upload', id, filled?: bool }] }
     * Retourne un ZIP en streaming.
     */
    public function exportZip(Request $request)
    {
        $request->validate([
            'dossier_id' => 'required|exists:dossiers,id',
            'items' => 'required|array|min:1',
            'items.*.kind' => 'required|in:ircc,fo,supplementary,client_upload',
            'items.*.id' => 'required|integer',
            'items.*.filled' => 'nullable|boolean',
        ]);
        $dossier = Dossier::with('client')->findOrFail($request->input('dossier_id'));
        $zipName = 'dossier-' . $dossier->id . '-export-' . now()->format('Ymd-His') . '.zip';
        $tmpPath = storage_path('app/' . uniqid('export_') . '.zip');

        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['success' => false, 'message' => 'Impossible de créer le ZIP'], 500);
        }

        $added = 0;
        foreach ($request->input('items') as $item) {
            $kind = $item['kind'];
            $id = (int) $item['id'];
            $filled = !empty($item['filled']);

            if ($kind === 'ircc' || $kind === 'fo') {
                $doc = DossierDocument::where('dossier_id', $dossier->id)
                    ->where('doc_type', $kind)
                    ->where('id', $id)
                    ->first();
                if (!$doc) continue;
                // Si on demande la version remplie : priorité collab > client > rien
                if ($filled) {
                    $path = null;
                    if ($doc->filled_pdf_path && Storage::disk('local')->exists($doc->filled_pdf_path)) {
                        $path = $doc->filled_pdf_path;
                    } elseif ($doc->document_template_id) {
                        $clientFill = InvitationItem::whereHas('invitation', fn($q) => $q->where('dossier_id', $dossier->id))
                            ->where('document_template_id', $doc->document_template_id)
                            ->whereNotNull('pdf_filled_path')
                            ->orderByDesc('last_saved_at')
                            ->first();
                        if ($clientFill && Storage::disk('local')->exists($clientFill->pdf_filled_path)) {
                            $path = $clientFill->pdf_filled_path;
                        }
                    }
                } else {
                    $path = $doc->template_path;
                }
                if (!$path || !Storage::disk('local')->exists($path)) continue;
                $folder = $kind === 'ircc' ? 'IRCC' : 'Provincial-FO';
                $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'pdf';
                $name = $doc->name . ($filled ? ' (rempli)' : '') . '.' . $extension;
                $zip->addFile(Storage::disk('local')->path($path), "{$folder}/{$name}");
                $added++;
            } elseif ($kind === 'supplementary') {
                $f = DossierSupplementaryFile::where('dossier_id', $dossier->id)->where('id', $id)->first();
                if (!$f || !Storage::disk('local')->exists($f->path)) continue;
                $zip->addFile(Storage::disk('local')->path($f->path), "Supplementaires/{$f->original_filename}");
                $added++;
            } elseif ($kind === 'client_upload') {
                $u = InvitationUpload::whereHas('invitation', fn($q) => $q->where('dossier_id', $dossier->id))
                    ->where('id', $id)->first();
                if (!$u || !Storage::disk('local')->exists($u->path)) continue;
                $zip->addFile(Storage::disk('local')->path($u->path), "Fichiers-Client/{$u->original_filename}");
                $added++;
            }
        }

        $zip->close();
        if ($added === 0) {
            @unlink($tmpPath);
            return response()->json(['success' => false, 'message' => 'Aucun fichier disponible parmi la sélection'], 422);
        }

        return response()->download($tmpPath, $zipName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /**
     * Renvoie le catalogue de TOUS les fichiers disponibles d'un dossier,
     * regroupés par catégorie, pour le modal d'export.
     */
    public function exportCatalog($dossierId)
    {
        $dossier = Dossier::with([
            'documents', 'supplementaryFiles',
            'invitations.uploads',
        ])->findOrFail($dossierId);

        $ircc = $dossier->documents->where('doc_type', 'ircc')->values()->map(fn($d) => [
            'id' => $d->id,
            'kind' => 'ircc',
            'name' => $d->name,
            'has_filled' => $d->has_filled_pdf, // accesseur : couvre collab + client
            'status' => $d->status,
        ]);
        $fo = $dossier->documents->where('doc_type', 'fo')->values()->map(fn($d) => [
            'id' => $d->id,
            'kind' => 'fo',
            'name' => $d->name,
            'has_filled' => $d->has_filled_pdf,
            'status' => $d->status,
        ]);
        $supp = $dossier->supplementaryFiles->map(fn($f) => [
            'id' => $f->id,
            'kind' => 'supplementary',
            'name' => $f->label,
            'filename' => $f->original_filename,
            'size' => $f->size,
            'mime_type' => $f->mime_type,
        ]);
        $clientUploads = collect();
        foreach ($dossier->invitations as $inv) {
            foreach ($inv->uploads as $u) {
                $clientUploads->push([
                    'id' => $u->id,
                    'kind' => 'client_upload',
                    'name' => $u->label,
                    'filename' => $u->original_filename,
                    'size' => $u->size,
                    'mime_type' => $u->mime_type,
                    'invitation_email' => $inv->email,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'ircc' => $ircc,
                'fo' => $fo,
                'supplementary' => $supp,
                'client_uploads' => $clientUploads->values(),
            ],
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function servePath(string $path, string $filename, ?string $mime, bool $forceDownload)
    {
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['success' => false, 'message' => 'Fichier introuvable'], 404);
        }
        $absPath = Storage::disk('local')->path($path);
        $disposition = $forceDownload ? 'attachment' : 'inline';
        return response()->file($absPath, [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
        ]);
    }

    public function format(DossierSupplementaryFile $f): array
    {
        return [
            'id' => $f->id,
            'dossier_id' => $f->dossier_id,
            'label' => $f->label,
            'original_filename' => $f->original_filename,
            'mime_type' => $f->mime_type,
            'size' => $f->size,
            'created_at' => $f->created_at?->format('Y-m-d H:i'),
        ];
    }
}
