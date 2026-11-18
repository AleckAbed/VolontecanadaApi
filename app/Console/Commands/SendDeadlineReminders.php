<?php

namespace App\Console\Commands;

use App\Mail\DeadlineDigestMail;
use App\Models\Admin;
use App\Models\Cabinet;
use App\Services\DeadlineService;
use App\Services\NotificationService;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Rappels quotidiens d'échéances, cabinet par cabinet.
 *
 * Une échéance notifie deux fois au plus : en entrant dans sa fenêtre de
 * rappel, puis si elle est dépassée (dedupe_key). Chaque personne reçoit en
 * plus un seul courriel récapitulant ses nouveaux rappels du jour.
 *
 * Planifiée chaque jour à 7 h (routes/console.php) : en production, le
 * planificateur Laravel doit tourner (cron « php artisan schedule:run »).
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'cabinet:deadline-reminders {--no-mail : Notifications dans l\'application seulement}';

    protected $description = 'Notifie le personnel des échéances qui approchent ou sont dépassées';

    public function handle(DeadlineService $deadlines, NotificationService $notifications): int
    {
        $totalNotifications = 0;
        $totalMails = 0;

        foreach (Cabinet::where('is_active', true)->get() as $cabinet) {
            CurrentCabinet::runAs($cabinet->id, function () use ($cabinet, $deadlines, $notifications, &$totalNotifications, &$totalMails) {
                $staffIds = $notifications->caseStaffIds();
                if ($staffIds->isEmpty()) {
                    return;
                }

                $perAdmin = [];
                foreach ($deadlines->needingAction() as $item) {
                    $overdue = $item['urgency'] === 'overdue';
                    $title = ($overdue ? 'En retard : ' : 'À venir : ') . $item['label']
                        . ($item['client'] ? " — {$item['client']['name']}" : '');
                    $body = ($item['dossier'] ? "{$item['dossier']['name']} · " : '') . "Échéance le {$item['due_date']}";
                    $dedupe = "deadline:{$item['key']}:{$item['due_date']}:" . ($overdue ? 'overdue' : 'reminder');

                    $created = $notifications->notify($staffIds, $overdue ? 'deadline_overdue' : 'deadline_reminder', $title, $body, $item['link'], $dedupe);
                    foreach ($notifications->lastDeliveredAdminIds() as $adminId) {
                        $perAdmin[$adminId][] = ['title' => $title, 'body' => $body, 'link' => $item['link'], 'overdue' => $overdue];
                    }
                    $totalNotifications += $created->count();
                }

                if ($this->option('no-mail')) {
                    return;
                }
                foreach ($perAdmin as $adminId => $items) {
                    $admin = Admin::find($adminId);
                    if (! $admin?->email || ! \App\Support\NotificationTypes::wants($admin, 'deadline_digest', 'email')) {
                        continue;
                    }
                    try {
                        Mail::to($admin->email)->send(new DeadlineDigestMail($admin, $cabinet->name, $items));
                        $totalMails++;
                    } catch (\Throwable $e) {
                        Log::error('Échec du récapitulatif d\'échéances', ['admin_id' => $adminId, 'error' => $e->getMessage()]);
                    }
                }
            });
        }

        $this->info("{$totalNotifications} notification(s) créée(s), {$totalMails} courriel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
