<?php

namespace App\Services\Calendar;

use App\Models\Admin;
use App\Models\Appointment;
use Carbon\CarbonInterface;

/** Agenda factice pour les tests : plages occupées programmables, appels enregistrés. */
class FakeCalendarGateway implements CalendarGateway
{
    /** @var array<int, array{0: CarbonInterface, 1: CarbonInterface}> */
    public array $busy = [];

    public array $created = [];

    public array $deleted = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function authorizationUrl(string $state): string
    {
        return "https://accounts.google.test/auth?state={$state}";
    }

    public function exchangeCode(string $code): array
    {
        return ['refresh_token' => "refresh-{$code}", 'email' => 'agenda@example.test'];
    }

    public function busy(Admin $admin, CarbonInterface $from, CarbonInterface $to): array
    {
        return $admin->google_refresh_token ? $this->busy : [];
    }

    public function createEvent(Admin $admin, Appointment $appointment, string $title, string $description, bool $withMeet): array
    {
        $this->created[] = ['admin' => $admin->id, 'appointment' => $appointment->id, 'title' => $title, 'meet' => $withMeet];

        return ['event_id' => 'evt_' . $appointment->id, 'meeting_url' => $withMeet ? 'https://meet.google.test/abc-defg-hij' : null];
    }

    public function deleteEvent(Admin $admin, string $eventId): void
    {
        $this->deleted[] = $eventId;
    }
}
