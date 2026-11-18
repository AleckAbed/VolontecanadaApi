<?php

namespace App\Mail;

use App\Models\Appointment;
use App\Models\BookingService;
use App\Models\Cabinet;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Confirmation (avec invitation .ics), rappel la veille ou annulation d'un rendez-vous, envoyé au client. */
class AppointmentMail extends QueuedMail
{
    public function __construct(public Appointment $appointment, public ?Cabinet $cabinet, public string $kind) {}

    public function envelope(): Envelope
    {
        $cabinet = $this->cabinet?->name ?? 'Votre cabinet';

        return new Envelope(
            subject: match ($this->kind) {
                'cancelled' => "Rendez-vous annulé — {$cabinet}",
                'reminder' => "Rappel : rendez-vous le {$this->localStart()->locale('fr_CA')->isoFormat('D MMMM [à] HH:mm')} — {$cabinet}",
                default => "Rendez-vous confirmé le {$this->localStart()->locale('fr_CA')->isoFormat('D MMMM [à] HH:mm')} — {$cabinet}",
            },
        );
    }

    public function content(): Content
    {
        $service = $this->appointment->service;

        return new Content(
            view: 'emails.appointment',
            with: [
                'appointment' => $this->appointment,
                'cabinetName' => $this->cabinet?->name ?? 'Votre cabinet',
                'serviceName' => $service?->name ?? 'Rendez-vous',
                'when' => $this->localStart()->locale('fr_CA')->isoFormat('dddd D MMMM YYYY [à] HH:mm'),
                'timezone' => $this->cabinet?->timezone ?? 'America/Toronto',
                'modeLabel' => BookingService::MEETING_MODES[$this->appointment->meeting_mode] ?? '',
                'location' => $service?->location,
                'isCancelled' => $this->kind === 'cancelled',
                'isReminder' => $this->kind === 'reminder',
                'manageUrl' => rtrim(config('app.frontend_url'), '/') . '/rendez-vous/' . $this->appointment->public_token,
            ],
        );
    }

    public function attachments(): array
    {
        if ($this->kind !== 'confirmed') {
            return [];
        }

        return [Attachment::fromData(fn () => $this->ics(), 'rendez-vous.ics')->withMime('text/calendar; charset=utf-8; method=REQUEST')];
    }

    private function localStart()
    {
        return $this->appointment->starts_at->copy()->setTimezone($this->cabinet?->timezone ?: 'America/Toronto');
    }

    /** Invitation iCalendar (RFC 5545) : ajout en un clic à l'agenda du client. */
    private function ics(): string
    {
        $a = $this->appointment;
        $esc = fn (string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
        $summary = $esc(($a->service?->name ?? 'Rendez-vous') . ' — ' . ($this->cabinet?->name ?? ''));
        $description = $esc(trim(($a->meeting_url ? "Lien de la rencontre : {$a->meeting_url}\n" : '')
            . 'Gérer ou annuler : ' . rtrim(config('app.frontend_url'), '/') . "/rendez-vous/{$a->public_token}"));
        $location = $esc($a->meeting_url ?: ($a->service?->location ?? ''));

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Plateforme cabinets immigration//FR',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            "UID:appointment-{$a->id}@" . parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:' . now('UTC')->format('Ymd\THis\Z'),
            'DTSTART:' . $a->starts_at->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:' . $a->ends_at->copy()->utc()->format('Ymd\THis\Z'),
            "SUMMARY:{$summary}",
            "DESCRIPTION:{$description}",
            "LOCATION:{$location}",
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }
}
