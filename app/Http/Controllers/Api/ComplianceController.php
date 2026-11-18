<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\ApplyRetentionPolicy;
use App\Http\Controllers\Controller;
use App\Mail\DataRequestMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Models\ChatbotSettings;
use App\Models\Client;
use App\Models\DataRequest;
use App\Models\DeletionLog;
use App\Models\Dossier;
use App\Services\Compliance\DataPurger;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Écran « Conformité » des gestionnaires : conservation des dossiers (3.9),
 * demandes des clients (3.10), registre des destructions, état de l'IA (2.3).
 */
class ComplianceController extends Controller
{
    public function overview()
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $years = $cabinet->retention_years;

        // Dossiers fermés qui seront détruits dans les 90 prochains jours, et dossiers conservés.
        $upcoming = collect();
        if ($years) {
            $cutoff = now()->subYears($years);
            $upcoming = Dossier::with('client:id,first_name,last_name')
                ->whereNotNull('closed_at')->where('retention_hold', false)
                ->where('closed_at', '<=', $cutoff->copy()->addDays(90))
                ->orderBy('closed_at')->limit(100)->get()
                ->map(fn (Dossier $d) => $this->dossierRow($d, $years));
        }
        $held = Dossier::with('client:id,first_name,last_name')->where('retention_hold', true)
            ->orderBy('closed_at')->limit(100)->get()->map(fn (Dossier $d) => $this->dossierRow($d, $years));

        $admins = Admin::pluck('name', 'id');
        $ai = ChatbotSettings::current();

        return response()->json(['success' => true, 'data' => [
            'retention_years' => $years,
            'notice_days' => ApplyRetentionPolicy::NOTICE_DAYS,
            'closed_dossiers' => Dossier::whereNotNull('closed_at')->count(),
            'upcoming' => $upcoming->values(),
            'held' => $held->values(),
            'requests' => DataRequest::orderByRaw("status = 'pending' desc")->latest()->limit(50)->get()
                ->map(fn (DataRequest $r) => [
                    'id' => $r->id, 'client_id' => $r->client_id, 'client_name' => $r->client_name, 'client_email' => $r->client_email,
                    'message' => $r->message, 'status' => $r->status, 'response' => $r->response,
                    'handled_by' => $r->handled_by ? ($admins[$r->handled_by] ?? null) : null,
                    'handled_at' => $r->handled_at?->toIso8601String(), 'created_at' => $r->created_at->toIso8601String(),
                ]),
            'log' => DeletionLog::latest('created_at')->limit(100)->get()->map(fn (DeletionLog $l) => [
                'id' => $l->id, 'label' => $l->label, 'subject_type' => $l->subject_type,
                'reason' => DeletionLog::REASONS[$l->reason] ?? $l->reason, 'files_deleted' => $l->files_deleted,
                'performed_by' => $l->performed_by ? ($admins[$l->performed_by] ?? null) : 'Automatique',
                'created_at' => $l->created_at?->toIso8601String(),
            ]),
            'ai' => [
                'enabled' => (bool) $ai->enabled,
                'enabled_at' => $ai->enabled_at?->toIso8601String(),
                'enabled_by' => $ai->enabled_by ? ($admins[$ai->enabled_by] ?? null) : null,
            ],
            'storage' => [
                'documents_disk' => config('filesystems.documents') === 'local' ? 'local' : 'objet',
                'backups' => (bool) config('backup.encryption_key'),
            ],
        ]]);
    }

    public function updateRetention(Request $request)
    {
        $data = $request->validate(['retention_years' => 'nullable|integer|min:1|max:25']);
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $cabinet->forceFill(['retention_years' => $data['retention_years'] ?? null])->save();

        return response()->json(['success' => true, 'message' => $cabinet->retention_years
            ? "Les dossiers fermés seront détruits {$cabinet->retention_years} an(s) après leur fermeture, avec un préavis de 30 jours."
            : 'Les dossiers fermés sont conservés sans limite de durée.']);
    }

    public function holdDossier(Request $request, int $id)
    {
        $data = $request->validate(['hold' => 'required|boolean']);
        $dossier = Dossier::findOrFail($id);
        $dossier->forceFill(['retention_hold' => $data['hold'], 'retention_notified_at' => null])->save();

        return response()->json(['success' => true, 'message' => $data['hold']
            ? 'Ce dossier sera conservé malgré la durée de conservation.'
            : 'Ce dossier suit de nouveau la durée de conservation du cabinet.']);
    }

    /** Applique une demande de suppression : destruction du client et de ses dossiers. */
    public function completeRequest(Request $request, int $id, DataPurger $purger)
    {
        $data = $request->validate([
            'response' => 'nullable|string|max:2000',
            'confirm' => 'required|accepted',
        ]);
        $dr = DataRequest::where('status', 'pending')->findOrFail($id);
        $result = ['files' => 0, 'kept_for_invoices' => false];
        if ($dr->client_id && ($client = Client::find($dr->client_id))) {
            $result = $purger->purgeClient($client, 'erasure_request', $request->user()->id);
        }
        $dr->forceFill(['status' => 'completed', 'response' => $data['response'] ?? null, 'handled_by' => $request->user()->id, 'handled_at' => now()])->save();
        $this->notifyClient($dr);

        return response()->json(['success' => true, 'message' => 'Données supprimées (' . $result['files'] . ' fichier(s)).'
            . ($result['kept_for_invoices'] ? ' Le nom et le courriel restent sur les factures (obligation fiscale).' : '') . ' Le client est prévenu par courriel.']);
    }

    public function rejectRequest(Request $request, int $id)
    {
        $data = $request->validate(['response' => 'required|string|min:10|max:2000']);
        $dr = DataRequest::where('status', 'pending')->findOrFail($id);
        $dr->forceFill(['status' => 'rejected', 'response' => $data['response'], 'handled_by' => $request->user()->id, 'handled_at' => now()])->save();
        $this->notifyClient($dr);

        return response()->json(['success' => true, 'message' => 'Demande refusée. Le client reçoit votre explication par courriel.']);
    }

    private function notifyClient(DataRequest $dr): void
    {
        try {
            Mail::to($dr->client_email)->send(new DataRequestMail($dr, Cabinet::find($dr->cabinet_id)?->name ?? 'Votre cabinet'));
        } catch (\Throwable $e) {
            Log::error('Réponse à la demande de données non envoyée', ['data_request_id' => $dr->id, 'error' => $e->getMessage()]);
        }
    }

    private function dossierRow(Dossier $d, ?int $years): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'client' => $d->client ? trim("{$d->client->first_name} {$d->client->last_name}") : null,
            'client_id' => $d->client_id,
            'status' => $d->status,
            'closed_at' => $d->closed_at?->toDateString(),
            'destroy_on' => $years && $d->closed_at ? $d->closed_at->copy()->addYears($years)->toDateString() : null,
            'hold' => (bool) $d->retention_hold,
        ];
    }
}
