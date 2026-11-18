<?php

namespace App\Services\Compliance;

use App\Models\Client;
use App\Models\DeletionLog;
use App\Models\Dossier;
use App\Support\Documents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Destruction réelle des renseignements d'un dossier ou d'un client (3.9, 3.10) :
 * fichiers sur le disque des documents ET lignes en base, y compris celles que
 * les clés étrangères ne suppriment pas d'elles-mêmes (invitations, documents
 * à remplir : « set null »). Chaque destruction est inscrite au registre
 * (deletion_logs), sans renseignement personnel.
 *
 * Appelé dans le contexte du cabinet concerné (requête ou CurrentCabinet::runAs).
 */
class DataPurger
{
    /** Détruit un dossier, ses fichiers et ses formulaires. Renvoie le nombre de fichiers supprimés. */
    public function purgeDossier(Dossier $dossier, string $reason, ?int $performedBy = null, bool $log = true): int
    {
        $id = $dossier->id;
        $label = "Dossier n° {$id}" . ($dossier->service_name ? " — {$dossier->service_name}" : '')
            . ($dossier->closed_at ? ' (fermé le ' . $dossier->closed_at->format('Y-m-d') . ')' : '');

        $paths = collect()
            ->merge(DB::table('dossier_documents')->where('dossier_id', $id)->get(['template_path', 'filled_pdf_path'])
                ->flatMap(fn ($r) => [$r->template_path, $r->filled_pdf_path]))
            ->merge(DB::table('dossier_uploads')->where('dossier_id', $id)->pluck('path'))
            ->merge(DB::table('dossier_supplementary_files')->where('dossier_id', $id)->pluck('path'));

        $invitationIds = DB::table('invitations')->where('dossier_id', $id)->pluck('id');
        $requestIds = DB::table('document_requests')->where('dossier_id', $id)->pluck('id');
        $paths = $paths->merge($this->formPaths($invitationIds, $requestIds));

        $files = 0;
        DB::transaction(function () use ($dossier, $invitationIds, $requestIds) {
            DB::table('invitations')->whereIn('id', $invitationIds)->delete();
            DB::table('document_requests')->whereIn('id', $requestIds)->delete();
            $dossier->delete(); // documents, téléversements, pièces, échéances : en cascade
        });
        $files = Documents::deleteMany($paths->unique());

        if ($log) {
            $this->log($dossier->cabinet_id, 'dossier', $id, $label, $reason, $files, $performedBy);
        }

        return $files;
    }

    /**
     * Efface un client et tout ce qui le concerne.
     * S'il a des factures, on garde seulement son nom et son courriel avec les
     * factures (obligation fiscale) : le reste est détruit et la fiche marquée « effacée ».
     *
     * @return array{files:int, kept_for_invoices:bool}
     */
    public function purgeClient(Client $client, string $reason, ?int $performedBy = null): array
    {
        $files = 0;
        foreach (Dossier::where('client_id', $client->id)->get() as $dossier) {
            $files += $this->purgeDossier($dossier, $reason, $performedBy, log: false);
        }

        $invitationIds = DB::table('invitations')->where('client_id', $client->id)->pluck('id');
        $requestIds = DB::table('document_requests')->where('client_id', $client->id)->pluck('id');
        $paths = $this->formPaths($invitationIds, $requestIds);

        $keep = DB::table('invoices')->where('client_id', $client->id)->exists();

        DB::transaction(function () use ($client, $invitationIds, $requestIds, $keep) {
            DB::table('invitations')->whereIn('id', $invitationIds)->delete();
            DB::table('document_requests')->whereIn('id', $requestIds)->delete();
            DB::table('questionnaire_requests')->where('client_id', $client->id)->delete();
            DB::table('appointments')->where('client_id', $client->id)->delete();
            DB::table('mandates')->where('client_id', $client->id)->delete();
            $client->tokens()->delete();

            if ($keep) {
                DB::table('client_profiles')->where('client_id', $client->id)->delete();
                DB::table('family_members')->where('client_id', $client->id)->delete();
                DB::table('deadlines')->where('client_id', $client->id)->delete();
                $client->forceFill([
                    'phone' => null, 'date_of_birth' => null, 'nationality' => null, 'country_of_residence' => null,
                    'in_canada' => null, 'passport_number' => null, 'address' => null,
                    'password' => Str::random(64), 'is_active' => false, 'erased_at' => now(),
                ])->save();
            } else {
                $client->delete();
            }
        });
        $files += Documents::deleteMany($paths->unique());

        $this->log($client->cabinet_id, 'client', $client->id, "Client n° {$client->id}"
            . ($keep ? ' (identité conservée avec ses factures)' : ''), $reason, $files, $performedBy);

        return ['files' => $files, 'kept_for_invoices' => $keep];
    }

    /** Fichiers des formulaires envoyés au client (PDF remplis, pièces téléversées). */
    private function formPaths($invitationIds, $requestIds)
    {
        return collect()
            ->merge(DB::table('invitation_items')->whereIn('invitation_id', $invitationIds)->pluck('pdf_filled_path'))
            ->merge(DB::table('invitation_uploads')->whereIn('invitation_id', $invitationIds)->pluck('path'))
            ->merge(DB::table('document_requests')->whereIn('id', $requestIds)->pluck('pdf_filled_path'));
    }

    private function log(int $cabinetId, string $type, int $id, string $label, string $reason, int $files, ?int $by): void
    {
        DeletionLog::create([
            'cabinet_id' => $cabinetId,
            'subject_type' => $type,
            'subject_id' => $id,
            'label' => Str::limit($label, 250),
            'reason' => $reason,
            'files_deleted' => $files,
            'performed_by' => $by,
        ]);
    }
}
