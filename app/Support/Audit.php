<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Collaborator;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Écriture dans le journal d'activité (2.6).
 *
 * - L'auteur est déduit de la requête (personnel, collaborateur, client, lien public)
 *   ou « système » en console.
 * - Le journal ne contient que des identifiants et des noms de champs : aucun
 *   renseignement sur le client n'y est copié.
 * - Une consultation ou une modification répétée du même élément par la même personne
 *   n'est inscrite qu'une fois toutes les 10 minutes (sauvegarde automatique, rechargements).
 * - Un échec d'écriture du journal n'empêche jamais l'action elle-même.
 */
final class Audit
{
    private const DEDUPE_SECONDS = 600;

    private const DEDUPED = ['viewed', 'updated', 'downloaded'];

    /** Désactivation temporaire (migrations de données, tests ciblés). */
    private static bool $muted = false;

    public static function log(string $action, ?string $subjectType = null, ?int $subjectId = null, array $fields = [], ?int $cabinetId = null, ?array $actor = null, ?array $context = null): void
    {
        if (self::$muted) {
            return;
        }
        try {
            $cabinetId ??= CurrentCabinet::id();
            if (! $cabinetId) {
                return;
            }
            $actor ??= self::actor();
            if (in_array($action, self::DEDUPED, true)) {
                $key = 'audit:' . implode(':', [$cabinetId, $actor['type'], $actor['id'] ?? 0, $action, $subjectType, $subjectId]);
                if (! Cache::add($key, 1, self::DEDUPE_SECONDS)) {
                    return;
                }
            }
            [$dossierId, $clientId] = $context ?? ($subjectType ? self::context($subjectType, $subjectId) : [null, null]);
            if ($dossierId && ! $clientId) {
                $clientId = DB::table('dossiers')->where('id', $dossierId)->value('client_id');
            }
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

            AuditLog::create([
                'cabinet_id' => $cabinetId,
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'] ?? null,
                'actor_name' => isset($actor['name']) ? mb_substr($actor['name'], 0, 120) : null,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'dossier_id' => $dossierId,
                'client_id' => $clientId,
                'fields' => $fields ? array_values($fields) : null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Journal d’activité non écrit', ['action' => $action, 'subject' => "{$subjectType}#{$subjectId}", 'error' => $e->getMessage()]);
        }
    }

    public static function forModel(string $action, Model $model, array $fields = []): void
    {
        $type = self::typeOf($model);
        if (! $type) {
            return;
        }
        $id = (int) $model->getKey();
        $cabinetId = $type === 'cabinet' ? $id : ((int) $model->getAttribute('cabinet_id') ?: null);
        // Contexte lu sur le modèle lui-même : encore disponible après une suppression.
        $context = match ($type) {
            'dossier' => [$id, $model->getAttribute('client_id')],
            'client' => [null, $id],
            default => ($model->getAttribute('dossier_id') || $model->getAttribute('client_id'))
                ? [$model->getAttribute('dossier_id'), $model->getAttribute('client_id')]
                : null,
        };
        self::log($action, $type, $id, $fields, $cabinetId, null, $context);
    }

    public static function muted(callable $fn): mixed
    {
        $previous = self::$muted;
        self::$muted = true;
        try {
            return $fn();
        } finally {
            self::$muted = $previous;
        }
    }

    /** Auteur de l'action en cours. */
    public static function actor(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['type' => 'system', 'id' => null, 'name' => 'Tâche automatique'];
        }
        $user = request()->user();
        return match (true) {
            $user instanceof Admin => ['type' => 'admin', 'id' => $user->id, 'name' => $user->name],
            $user instanceof Collaborator => ['type' => 'collaborator', 'id' => $user->id, 'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->name ?? 'Collaborateur')],
            $user instanceof Client => ['type' => 'client', 'id' => $user->id, 'name' => 'Client'],
            default => ['type' => 'public', 'id' => null, 'name' => 'Lien client'],
        };
    }

    /** [dossier_id, client_id] de l'élément, pour l'historique d'un dossier ou d'un client. */
    public static function context(string $type, ?int $id): array
    {
        if (! $id) {
            return [null, null];
        }
        $row = fn (string $table, array $cols) => DB::table($table)->where('id', $id)->first($cols);

        switch ($type) {
            case 'client':
                return [null, $id];
            case 'dossier':
                return [$id, DB::table('dossiers')->where('id', $id)->value('client_id')];
            case 'family_member':
            case 'questionnaire':
            case 'appointment':
                $table = ['family_member' => 'family_members', 'questionnaire' => 'questionnaire_requests', 'appointment' => 'appointments'][$type];
                return [null, DB::table($table)->where('id', $id)->value('client_id')];
            case 'dossier_document':
            case 'dossier_upload':
            case 'supplementary_file':
                $table = ['dossier_document' => 'dossier_documents', 'dossier_upload' => 'dossier_uploads', 'supplementary_file' => 'dossier_supplementary_files'][$type];
                $dossierId = DB::table($table)->where('id', $id)->value('dossier_id');
                return [$dossierId, $dossierId ? DB::table('dossiers')->where('id', $dossierId)->value('client_id') : null];
            case 'invitation':
            case 'document_request':
            case 'invoice':
            case 'mandate':
            case 'deadline':
                $table = ['invitation' => 'invitations', 'document_request' => 'document_requests', 'invoice' => 'invoices', 'mandate' => 'mandates', 'deadline' => 'deadlines'][$type];
                $r = $row($table, ['dossier_id', 'client_id']);
                return [$r?->dossier_id, $r?->client_id];
            case 'invitation_item':
            case 'invitation_upload':
                $table = $type === 'invitation_item' ? 'invitation_items' : 'invitation_uploads';
                $invId = DB::table($table)->where('id', $id)->value('invitation_id');
                $r = $invId ? DB::table('invitations')->where('id', $invId)->first(['dossier_id', 'client_id']) : null;
                return [$r?->dossier_id, $r?->client_id];
        }

        return [null, null];
    }

    public static function typeOf(Model $model): ?string
    {
        return [
            \App\Models\Client::class => 'client',
            \App\Models\FamilyMember::class => 'family_member',
            \App\Models\Dossier::class => 'dossier',
            \App\Models\DossierDocument::class => 'dossier_document',
            \App\Models\DossierUpload::class => 'dossier_upload',
            \App\Models\DossierSupplementaryFile::class => 'supplementary_file',
            \App\Models\Invitation::class => 'invitation',
            \App\Models\InvitationUpload::class => 'invitation_upload',
            \App\Models\DocumentRequest::class => 'document_request',
            \App\Models\QuestionnaireRequest::class => 'questionnaire',
            \App\Models\Invoice::class => 'invoice',
            \App\Models\Mandate::class => 'mandate',
            \App\Models\Appointment::class => 'appointment',
            \App\Models\Deadline::class => 'deadline',
            \App\Models\Admin::class => 'admin',
            \App\Models\Collaborator::class => 'collaborator',
            \App\Models\FileItem::class => 'file_item',
            \App\Models\Cabinet::class => 'cabinet',
            \App\Models\ChatbotSettings::class => 'chatbot_settings',
            \App\Models\DataRequest::class => 'data_request',
        ][$model::class] ?? null;
    }
}
