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
        $cabinet = $this->cabinet?->name ?? __('emails.immigration_firm');
        $when = $this->localStart()->locale(self::carbonLocale())->isoFormat(self::shortFormat());

        return new Envelope(subject: __('emails.appointment.subject_' . $this->kindKey(), ['when' => $when, 'cabinet' => $cabinet]));
    }

    public function content(): Content
    {
        $service = $this->appointment->service;

        return new Content(
            view: 'emails.appointment',
            with: [
                'appointment' => $this->appointment,
                'cabinetName' => $this->cabinet?->name ?? __('emails.immigration_firm'),
                'serviceName' => $service?->name ?? __('emails.appointment.default_service'),
                'when' => $this->localStart()->locale(self::carbonLocale())->isoFormat(self::longFormat()),
                'timezone' => $this->cabinet?->timezone ?? 'America/Toronto',
                'modeLabel' => $this->appointment->meeting_mode ? __('emails.appointment.mode_' . $this->appointment->meeting_mode) : '',
                'kind' => $this->kindKey(),
                'location' => $service?->location,
                'isCancelled' => $this->kind === 'cancelled',
                'isReminder' => $this->kind === 'reminder',
                'isRescheduled' => $this->kind === 'rescheduled',
                'manageUrl' => rtrim(config('app.frontend_url'), '/') . '/rendez-vous/' . $this->appointment->public_token,
            ],
        );
    }

    public function attachments(): array
    {
        if (! in_array($this->kind, ['confirmed', 'rescheduled'], true)) {
            return [];
        }

        return [Attachment::fromData(fn () => $this->ics(), 'rendez-vous.ics')->withMime('text/calendar; charset=utf-8; method=REQUEST')];
    }

    /** confirmed | rescheduled | reminder | cancelled */
    private function kindKey(): string
    {
        return in_array($this->kind, ['rescheduled', 'reminder', 'cancelled'], true) ? $this->kind : 'confirmed';
    }

    private static function carbonLocale(): string
    {
        return ['fr' => 'fr_CA', 'en' => 'en_CA', 'es' => 'es'][app()->getLocale()] ?? 'fr_CA';
    }

    private static function shortFormat(): string
    {
        return ['en' => 'MMMM D [at] h:mm A', 'es' => 'D [de] MMMM [a las] HH:mm'][app()->getLocale()] ?? 'D MMMM [à] HH:mm';
    }

    private static function longFormat(): string
    {
        return ['en' => 'dddd, MMMM D, YYYY [at] h:mm A', 'es' => 'dddd D [de] MMMM [de] YYYY [a las] HH:mm'][app()->getLocale()] ?? 'dddd D MMMM YYYY [à] HH:mm';
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
            'SEQUENCE:' . (int) $a->reschedule_count,
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
