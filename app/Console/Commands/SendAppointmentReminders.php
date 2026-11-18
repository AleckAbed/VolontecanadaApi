<?php

namespace App\Console\Commands;

use App\Mail\AppointmentMail;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Rappel par courriel la veille d'un rendez-vous confirmé (une seule fois).
 * Pas de rappel pour un rendez-vous réservé moins de 24 h avant : la
 * confirmation vient d'arriver.
 *
 * Planifiée toutes les 15 minutes (routes/console.php).
 */
class SendAppointmentReminders extends Command
{
    public const HOURS_BEFORE = 24;

    protected $signature = 'booking:send-reminders';

    protected $description = 'Envoie le rappel des rendez-vous qui ont lieu dans les prochaines 24 h';

    public function handle(): int
    {
        $sent = 0;

        foreach (Cabinet::where('is_active', true)->get() as $cabinet) {
            CurrentCabinet::runAs($cabinet->id, function () use ($cabinet, &$sent) {
                $due = Appointment::where('status', 'confirmed')
                    ->whereNull('reminder_sent_at')
                    ->where('starts_at', '>', now())
                    ->where('starts_at', '<=', now()->addHours(self::HOURS_BEFORE))
                    ->with(['service', 'host'])
                    ->get()
                    ->filter(fn (Appointment $a) => $a->created_at->lte($a->starts_at->copy()->subHours(self::HOURS_BEFORE)));

                foreach ($due as $appointment) {
                    // Marqué avant l'envoi : jamais deux rappels, même si la commande se chevauche.
                    $claimed = Appointment::whereKey($appointment->id)->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => now()]);
                    if (! $claimed) {
                        continue;
                    }
                    try {
                        Mail::to($appointment->email)->send(new AppointmentMail($appointment, $cabinet, 'reminder'));
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::error('Rappel de rendez-vous non envoyé', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
                    }
                }
            });
        }

        $this->info("{$sent} rappel(s) de rendez-vous.");

        return self::SUCCESS;
    }
}
