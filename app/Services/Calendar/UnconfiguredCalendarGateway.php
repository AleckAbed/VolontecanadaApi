<?php

namespace App\Services\Calendar;

use App\Models\Admin;
use App\Models\Appointment;
use Carbon\CarbonInterface;
use RuntimeException;

/** Pas de clés Google : la synchronisation d'agenda est simplement indisponible. */
class UnconfiguredCalendarGateway implements CalendarGateway
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state): string
    {
        throw new RuntimeException('La connexion Google Agenda n\'est pas configurée sur la plateforme.');
    }

    public function exchangeCode(string $code): array
    {
        throw new RuntimeException('La connexion Google Agenda n\'est pas configurée sur la plateforme.');
    }

    public function busy(Admin $admin, CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }

    public function createEvent(Admin $admin, Appointment $appointment, string $title, string $description, bool $withMeet): array
    {
        throw new RuntimeException('La connexion Google Agenda n\'est pas configurée sur la plateforme.');
    }

    public function deleteEvent(Admin $admin, string $eventId): void
    {
    }
}
