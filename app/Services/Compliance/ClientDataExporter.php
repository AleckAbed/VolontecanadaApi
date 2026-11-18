<?php

namespace App\Services\Compliance;

use App\Models\Client;
use App\Services\ClientProfileService;
use App\Support\Documents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Copie des renseignements d'un client (3.10, droit d'accès et de portabilité) :
 * une archive ZIP avec un fichier JSON structuré et les fichiers le concernant.
 * Les notes internes du cabinet ne sont pas incluses.
 */
class ClientDataExporter
{
    public function __construct(private ClientProfileService $profiles) {}

    /** Construit l'archive dans un fichier temporaire et renvoie son chemin. */
    public function build(Client $client): string
    {
        $dossiers = DB::table('dossiers')->where('client_id', $client->id)->get();
        $dossierIds = $dossiers->pluck('id');
        $invitationIds = DB::table('invitations')->where('client_id', $client->id)->pluck('id');

        $data = [
            'exporte_le' => now()->toIso8601String(),
            'client' => collect($client->only([
                'first_name', 'last_name', 'email', 'phone', 'date_of_birth', 'nationality',
                'country_of_residence', 'in_canada', 'passport_number', 'address', 'created_at',
            ]))->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v),
            'profil_immigration' => $this->profiles->resolve($client),
            'membres_de_la_famille' => DB::table('family_members')->where('client_id', $client->id)
                ->get(['first_name', 'last_name', 'relationship', 'date_of_birth', 'nationality', 'country_of_residence', 'passport_number', 'email', 'phone']),
            'dossiers' => $dossiers->map(fn ($d) => [
                'nom' => $d->name, 'service' => $d->service_name, 'statut' => $d->status,
                'ouvert_le' => $d->opened_at, 'date_limite' => $d->deadline_at, 'ferme_le' => $d->closed_at,
            ]),
            'questionnaires' => DB::table('questionnaire_requests')->where('client_id', $client->id)
                ->get(['status', 'form_data', 'created_at', 'updated_at'])
                ->map(fn ($q) => ['statut' => $q->status, 'reponses' => json_decode((string) $q->form_data, true), 'cree_le' => $q->created_at]),
            'rendez_vous' => DB::table('appointments')->where('client_id', $client->id)
                ->get(['starts_at', 'ends_at', 'status', 'meeting_mode', 'notes']),
            'mandats' => DB::table('mandates')->where('client_id', $client->id)
                ->get(['title', 'body', 'status', 'sent_at', 'signed_name', 'signed_at', 'signed_ip']),
            'factures' => DB::table('invoices')->where('client_id', $client->id)->whereNot('status', 'draft')
                ->get(['number', 'status', 'currency', 'issued_at', 'due_date', 'subtotal_cents', 'tax_cents', 'total_cents', 'paid_at']),
        ];

        $path = storage_path('app/tmp/export-' . Str::random(16) . '.zip');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l’archive.');
        }
        $zip->addFromString('mes-donnees.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('LISEZ-MOI.txt', "Copie de vos renseignements personnels détenus par le cabinet.\n"
            . "mes-donnees.json : vos informations, dossiers, questionnaires, rendez-vous, mandats et factures.\n"
            . "fichiers/ : les documents que vous avez transmis et les formulaires remplis vous concernant.\n");

        $files = collect()
            ->merge(DB::table('invitation_uploads')->whereIn('invitation_id', $invitationIds)->get(['path', 'original_filename'])
                ->map(fn ($r) => ['fichiers/transmis', $r->path, $r->original_filename]))
            ->merge(DB::table('dossier_uploads')->whereIn('dossier_id', $dossierIds)->get(['path', 'original_filename'])
                ->map(fn ($r) => ['fichiers/dossiers', $r->path, $r->original_filename]))
            ->merge(DB::table('dossier_documents')->whereIn('dossier_id', $dossierIds)->whereNotNull('filled_pdf_path')->get(['filled_pdf_path', 'name'])
                ->map(fn ($r) => ['fichiers/formulaires', $r->filled_pdf_path, $r->name . '.pdf']));

        $disk = Documents::disk();
        $used = [];
        foreach ($files as [$folder, $file, $name]) {
            if (! $file || ! $disk->exists($file)) {
                continue;
            }
            $entry = $folder . '/' . $this->uniqueName(Str::of((string) ($name ?: basename($file)))->replaceMatches('#[\\\\/:*?"<>|]#', '_'), $used);
            $zip->addFromString($entry, $disk->get($file));
        }
        $zip->close();

        return $path;
    }

    private function uniqueName(string $name, array &$used): string
    {
        $candidate = $name;
        $i = 2;
        while (isset($used[$candidate])) {
            $candidate = pathinfo($name, PATHINFO_FILENAME) . " ({$i})" . (pathinfo($name, PATHINFO_EXTENSION) ? '.' . pathinfo($name, PATHINFO_EXTENSION) : '');
            $i++;
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
