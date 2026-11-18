<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\Invitation;
use App\Models\QuestionnaireRequest;
use App\Mail\NotificationMail;
use App\Support\NotificationTypes;
use App\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Notifications du personnel : dans la cloche de l'en-tête et, selon les préférences
 * de chacun (NotificationTypes), par courriel.
 * dedupe_key évite de répéter un même rappel.
 */
class NotificationService
{
    /** Destinataires réellement atteints par le dernier appel à notify() (cloche ou courriel). */
    private array $lastDelivered = [];

    public function lastDeliveredAdminIds(): array
    {
        return $this->lastDelivered;
    }

    /**
     * @param  iterable<int>  $adminIds
     * @return Collection<int, AdminNotification> notifications réellement créées (hors doublons)
     */
    public function notify(iterable $adminIds, string $type, string $title, ?string $body = null, ?string $link = null, ?string $dedupeKey = null): Collection
    {
        $created = collect();
        $this->lastDelivered = [];
        $admins = Admin::whereIn('id', collect($adminIds)->filter()->unique())->get()->keyBy('id');

        foreach ($admins as $adminId => $admin) {
            try {
                if ($dedupeKey && (AdminNotification::where('admin_id', $adminId)->where('dedupe_key', $dedupeKey)->exists()
                    || Cache::has("notif-sent:{$adminId}:{$dedupeKey}"))) {
                    continue;
                }
                $this->lastDelivered[] = (int) $adminId;
                if (NotificationTypes::wants($admin, $type, 'app')) {
                    $created->push(AdminNotification::create([
                        'admin_id' => $adminId,
                        'type' => $type,
                        'title' => $title,
                        'body' => $body,
                        'link' => $link,
                        'dedupe_key' => $dedupeKey,
                    ]));
                } elseif ($dedupeKey) {
                    // Pas d'entrée dans la cloche : on se souvient quand même de l'envoi pour ne pas le répéter.
                    Cache::put("notif-sent:{$adminId}:{$dedupeKey}", 1, now()->addDays(60));
                }
                if ($admin->email && $admin->is_active && NotificationTypes::wants($admin, $type, 'email')) {
                    Mail::to($admin->email)->send(new NotificationMail($admin, $title, $body, $link));
                }
            } catch (\Throwable $e) {
                // Une notification ratée ne doit jamais faire échouer l'action du client.
                Log::warning('Notification non créée', ['admin_id' => $adminId, 'type' => $type, 'error' => $e->getMessage()]);
            }
        }

        return $created;
    }

    /** Personnel actif qui suit les dossiers (propriétaires, administrateurs, conseillers). */
    public function caseStaffIds(): Collection
    {
        return Admin::where('is_active', true)
            ->whereIn('role', [Roles::OWNER, Roles::ADMIN, Roles::ADVISOR])
            ->pluck('id');
    }

    /** Gestionnaires actifs du cabinet (propriétaires, administrateurs). */
    public function managerIds(): Collection
    {
        return Admin::where('is_active', true)->whereIn('role', Roles::MANAGERS)->pluck('id');
    }

    /** Destinataires d'un événement d'envoi : la personne qui l'a envoyé, sinon le personnel qui suit les dossiers. */
    public function recipientsFor(?int $sentBy): Collection
    {
        if ($sentBy && Admin::where('id', $sentBy)->where('is_active', true)->exists()) {
            return collect([$sentBy]);
        }

        return $this->caseStaffIds();
    }

    public function invitationCompleted(Invitation $invitation): void
    {
        $this->notify(
            $this->recipientsFor($invitation->sent_by),
            'invitation_completed',
            $this->clientName($invitation) . ' a terminé son envoi',
            'Les formulaires et documents sont prêts à réviser.',
            "/envois/{$invitation->id}",
            "invitation_completed:{$invitation->id}",
        );
    }

    public function clientUploaded(Invitation $invitation, string $filename): void
    {
        $this->notify(
            $this->recipientsFor($invitation->sent_by),
            'client_upload',
            $this->clientName($invitation) . ' a téléversé un document',
            $filename,
            "/envois/{$invitation->id}",
        );
    }

    public function questionnaireSubmitted(QuestionnaireRequest $qr): void
    {
        $name = $qr->custom_name ?: ($qr->client ? trim("{$qr->client->first_name} {$qr->client->last_name}") : $qr->email);

        $this->notify(
            $this->recipientsFor($qr->sent_by),
            'questionnaire_submitted',
            "{$name} a soumis son questionnaire",
            null,
            ($invitationId = $qr->invitation_item_id ? \Illuminate\Support\Facades\DB::table('invitation_items')->where('id', $qr->invitation_item_id)->value('invitation_id') : null)
                ? "/envois/{$invitationId}"
                : "/questionnaires/{$qr->id}",
            "questionnaire_submitted:{$qr->id}",
        );
    }

    private function clientName(Invitation $invitation): string
    {
        return $invitation->custom_name
            ?: ($invitation->client ? trim("{$invitation->client->first_name} {$invitation->client->last_name}") : $invitation->email);
    }
}
