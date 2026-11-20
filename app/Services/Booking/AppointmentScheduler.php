<?php

namespace App\Services\Booking;

use App\Mail\AppointmentMail;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\BookingService;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Calendar\CalendarGateway;
use App\Services\InvoiceService;
use App\Services\MandateService;
use App\Services\NotificationService;
use App\Services\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Réservation d'un rendez-vous de bout en bout :
 *  créneau vérifié sous verrou → client retrouvé ou créé → facture si payant
 *  → paiement Stripe (Checkout) ou confirmation immédiate
 *  → à la confirmation : Google Agenda (+ Meet), courriel avec .ics, notification, mandat.
 */
class AppointmentScheduler
{
    public function __construct(
        private SlotFinder $slots,
        private CalendarGateway $calendar,
        private PaymentGateway $payments,
        private InvoiceService $invoices,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  array{name: string, email: string, phone?: ?string, notes?: ?string}  $attendee
     * @return array{appointment: Appointment, checkout_url: ?string}
     */
    public function book(Cabinet $cabinet, BookingService $service, CarbonImmutable $startUtc, array $attendee): array
    {
        $appointment = DB::transaction(function () use ($cabinet, $service, $startUtc, $attendee) {
            // Verrou sur l'hôte : deux réservations simultanées ne peuvent pas prendre le même créneau.
            Admin::acrossCabinets()->whereKey($service->host_admin_id)->lockForUpdate()->first();

            if (! $this->slots->isAvailable($cabinet, $service, $startUtc)) {
                throw new RuntimeException('Ce créneau n\'est plus disponible. Choisissez-en un autre.');
            }

            $client = Client::where('email', $attendee['email'])->first();
            if (! $client) {
                [$first, $last] = $this->splitName($attendee['name']);
                $client = Client::create([
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $attendee['email'],
                    'phone' => $attendee['phone'] ?? null,
                    'password' => null,
                    // Langue de la page de réservation : ses courriels partiront dans cette langue.
                    'preferred_locale' => in_array(app()->getLocale(), ['fr', 'en', 'es'], true) ? app()->getLocale() : 'fr',
                ]);
            }

            return Appointment::create([
                'booking_service_id' => $service->id,
                'host_admin_id' => $service->host_admin_id,
                'client_id' => $client->id,
                'name' => $attendee['name'],
                'email' => $attendee['email'],
                'phone' => $attendee['phone'] ?? null,
                'notes' => $attendee['notes'] ?? null,
                'starts_at' => $startUtc,
                'ends_at' => $startUtc->addMinutes($service->duration_minutes),
                'status' => $service->price_cents > 0 ? 'pending_payment' : 'confirmed',
                'meeting_mode' => $service->meeting_mode,
                'public_token' => Str::random(48),
            ]);
        });

        if ($service->price_cents <= 0) {
            $this->onConfirmed($appointment);

            return ['appointment' => $appointment, 'checkout_url' => null];
        }

        // Prestation payante : facture S6 émise pour ce rendez-vous.
        $client = Client::find($appointment->client_id);
        $local = $appointment->starts_at->copy()->setTimezone($cabinet->timezone ?: 'America/Toronto');
        $invoice = $this->invoices->saveDraft(null, $client, null, [[
            'description' => "{$service->name} — {$local->format('Y-m-d H:i')}",
            'quantity' => 1,
            'unit_price' => $service->price_cents / 100,
            'taxable' => $service->taxable,
        ]], $local->toDateString(), null, null);
        $invoice->forceFill(['status' => 'sent', 'issued_at' => now()])->save();
        $appointment->forceFill(['invoice_id' => $invoice->id])->save();

        $base = rtrim(config('app.frontend_url'), '/') . "/rendez-vous/{$appointment->public_token}";
        if ($this->payments->isConfigured() && $cabinet->stripe_charges_enabled) {
            try {
                $session = $this->payments->createInvoiceCheckout($invoice->fresh('client'), $cabinet, "{$base}?paiement=ok", "{$base}?paiement=annule");
                $invoice->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

                return ['appointment' => $appointment, 'checkout_url' => $session['url']];
            } catch (\Throwable $e) {
                Log::error('Checkout de réservation impossible', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            }
        }

        // Pas de paiement en ligne : rendez-vous confirmé, facture envoyée (paiement plus tard).
        $appointment->forceFill(['status' => 'confirmed'])->save();
        $this->invoices->send($invoice);
        $this->onConfirmed($appointment);

        return ['appointment' => $appointment, 'checkout_url' => null];
    }

    /** Appelé quand une facture est payée : confirme le rendez-vous qui l'attendait. */
    public function confirmByInvoice(Invoice $invoice): void
    {
        $appointment = Appointment::where('invoice_id', $invoice->id)->where('status', 'pending_payment')->first();
        if ($appointment) {
            $appointment->forceFill(['status' => 'confirmed'])->save();
            $this->onConfirmed($appointment);
        }
    }

    /**
     * Déplace un rendez-vous confirmé vers un autre créneau libre de la même prestation :
     * l'événement Google est recréé, le client reçoit une nouvelle invitation et le rappel
     * de la veille repartira pour la nouvelle date. La facture éventuelle ne change pas.
     */
    public function reschedule(Appointment $appointment, CarbonImmutable $newStartUtc, string $by): Appointment
    {
        $service = BookingService::findOrFail($appointment->booking_service_id);
        $cabinet = Cabinet::findOrFail($appointment->cabinet_id);
        $before = $this->localLabel($appointment);

        DB::transaction(function () use ($appointment, $service, $cabinet, $newStartUtc) {
            Admin::acrossCabinets()->whereKey($appointment->host_admin_id)->lockForUpdate()->first();
            if (! $this->slots->isAvailable($cabinet, $service, $newStartUtc, $appointment)) {
                throw new RuntimeException('Ce créneau n\'est plus disponible. Choisissez-en un autre.');
            }
            $appointment->forceFill([
                'starts_at' => $newStartUtc,
                'ends_at' => $newStartUtc->addMinutes($appointment->starts_at->diffInMinutes($appointment->ends_at)),
                'reminder_sent_at' => null,
                'reschedule_count' => $appointment->reschedule_count + 1,
            ])->save();
        });

        $host = Admin::find($appointment->host_admin_id);
        if ($host && $appointment->google_event_id) {
            try {
                $this->calendar->deleteEvent($host, $appointment->google_event_id);
            } catch (\Throwable $e) {
                Log::warning('Suppression de l\'ancien événement Google impossible', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            }
            $appointment->forceFill(['google_event_id' => null])->save();
        }
        if ($host?->google_refresh_token && $this->calendar->isConfigured()) {
            try {
                $event = $this->calendar->createEvent($host, $appointment, ($service->name ?? 'Rendez-vous') . " — {$appointment->name}",
                    trim(($appointment->notes ?? '') . "\n\nRendez-vous déplacé."), $appointment->meeting_mode === 'video');
                $appointment->forceFill(['google_event_id' => $event['event_id'], 'meeting_url' => $event['meeting_url'] ?? $appointment->meeting_url])->save();
            } catch (\Throwable $e) {
                Log::warning('Ajout à Google Agenda impossible', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            }
        }

        $this->sendMail($appointment, 'rescheduled');
        $this->notifications->notify(
            [$appointment->host_admin_id],
            'appointment_rescheduled',
            "Rendez-vous déplacé : {$appointment->name}",
            "{$before} → " . $this->localLabel($appointment) . ($by === 'client' ? ' (par le client)' : ''),
            '/admin/rendez-vous',
        );

        return $appointment->fresh();
    }

    public function cancel(Appointment $appointment, string $by): void
    {
        if ($appointment->status === 'cancelled') {
            return;
        }
        $appointment->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        // Facture non réglée de ce rendez-vous : annulée avec lui (un remboursement reste à faire à la main).
        $invoice = $appointment->invoice_id ? Invoice::find($appointment->invoice_id) : null;
        if ($invoice && in_array($invoice->status, ['draft', 'sent'], true)) {
            $this->invoices->void($invoice);
        }

        $host = Admin::find($appointment->host_admin_id);
        if ($host && $appointment->google_event_id) {
            try {
                $this->calendar->deleteEvent($host, $appointment->google_event_id);
            } catch (\Throwable $e) {
                Log::warning('Suppression de l\'événement Google impossible', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            }
        }

        $this->sendMail($appointment, 'cancelled');
        $this->notifications->notify(
            [$appointment->host_admin_id],
            'appointment_cancelled',
            "Rendez-vous annulé : {$appointment->name}",
            $this->localLabel($appointment) . ($by === 'client' ? ' — annulé par le client' : ''),
            '/admin/rendez-vous',
        );
    }

    private function onConfirmed(Appointment $appointment): void
    {
        $service = BookingService::find($appointment->booking_service_id);
        $host = Admin::find($appointment->host_admin_id);
        $cabinet = Cabinet::find($appointment->cabinet_id);

        if ($host?->google_refresh_token && $this->calendar->isConfigured()) {
            try {
                $event = $this->calendar->createEvent(
                    $host,
                    $appointment,
                    ($service?->name ?? 'Rendez-vous') . " — {$appointment->name}",
                    trim(($appointment->notes ?? '') . "\n\nRéservé en ligne via " . ($cabinet?->name ?? 'la plateforme') . '.'),
                    $appointment->meeting_mode === 'video',
                );
                $appointment->forceFill([
                    'google_event_id' => $event['event_id'],
                    'meeting_url' => $event['meeting_url'] ?? $appointment->meeting_url,
                ])->save();
            } catch (\Throwable $e) {
                Log::warning('Ajout à Google Agenda impossible', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            }
        }

        $this->sendMail($appointment, 'confirmed');
        $this->notifications->notify(
            [$appointment->host_admin_id],
            'appointment_booked',
            "Nouveau rendez-vous : {$appointment->name}",
            ($service?->name ? "{$service->name} — " : '') . $this->localLabel($appointment),
            '/admin/rendez-vous',
            "appointment_booked:{$appointment->id}",
        );

        if ($service?->send_mandate && $appointment->client_id) {
            try {
                app(MandateService::class)->send(Client::find($appointment->client_id), null, $appointment, null);
            } catch (\Throwable $e) {
                Log::error('Envoi du mandat après réservation impossible', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function sendMail(Appointment $appointment, string $kind): void
    {
        try {
            Mail::to($appointment->email)->send(new AppointmentMail($appointment->fresh(['service', 'host']), Cabinet::find($appointment->cabinet_id), $kind));
        } catch (\Throwable $e) {
            Log::error('Courriel de rendez-vous non envoyé', ['appointment_id' => $appointment->id, 'error' => $e->getMessage()]);
        }
    }

    private function localLabel(Appointment $appointment): string
    {
        $tz = Cabinet::find($appointment->cabinet_id)?->timezone ?: 'America/Toronto';

        return $appointment->starts_at->copy()->setTimezone($tz)->locale('fr_CA')->isoFormat('dddd D MMMM YYYY [à] HH:mm');
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? '—'];
    }
}
