<?php

namespace App\Services\Calendar;

use App\Models\Admin;
use App\Models\Appointment;
use Carbon\CarbonInterface;

/**
 * Agenda externe d'un membre du personnel (Google Agenda).
 * Implémentations : GoogleCalendarGateway, UnconfiguredCalendarGateway, FakeCalendarGateway.
 */
interface CalendarGateway
{
    public function isConfigured(): bool;

    /** Lien d'autorisation Google (le state lie la réponse à l'admin). */
    public function authorizationUrl(string $state): string;

    /** @return array{refresh_token: string, email: ?string} */
    public function exchangeCode(string $code): array;

    /**
     * Plages occupées de l'agenda principal de l'admin.
     *
     * @return array<int, array{0: CarbonInterface, 1: CarbonInterface}>
     */
    public function busy(Admin $admin, CarbonInterface $from, CarbonInterface $to): array;

    /** @return array{event_id: string, meeting_url: ?string} */
    public function createEvent(Admin $admin, Appointment $appointment, string $title, string $description, bool $withMeet): array;

    public function deleteEvent(Admin $admin, string $eventId): void;
}
