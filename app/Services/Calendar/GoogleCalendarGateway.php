<?php

namespace App\Services\Calendar;

use App\Models\Admin;
use App\Models\Appointment;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Google Agenda via l'API REST (sans SDK). Portées demandées :
 *  - calendar.events : créer / supprimer l'événement du rendez-vous (avec Meet) ;
 *  - calendar.freebusy : lire les plages occupées (pas le contenu des événements).
 */
class GoogleCalendarGateway implements CalendarGateway
{
    private const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.freebusy',
        'openid',
        'email',
    ];

    public function __construct(private string $clientId, private string $clientSecret, private string $redirectUri) {}

    public function isConfigured(): bool
    {
        return true;
    }

    public function authorizationUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent', // garantit un refresh_token
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if (! $res->ok() || ! $res->json('refresh_token')) {
            throw new RuntimeException('Google n\'a pas autorisé l\'accès à l\'agenda.');
        }

        $email = null;
        if ($idToken = $res->json('id_token')) {
            $payload = json_decode(base64_decode(strtr(explode('.', $idToken)[1] ?? '', '-_', '+/')), true);
            $email = $payload['email'] ?? null;
        }

        return ['refresh_token' => $res->json('refresh_token'), 'email' => $email];
    }

    public function busy(Admin $admin, CarbonInterface $from, CarbonInterface $to): array
    {
        $res = Http::withToken($this->accessToken($admin))->post('https://www.googleapis.com/calendar/v3/freeBusy', [
            'timeMin' => $from->toIso8601ZuluString(),
            'timeMax' => $to->toIso8601ZuluString(),
            'items' => [['id' => 'primary']],
        ]);
        if (! $res->ok()) {
            throw new RuntimeException('Lecture de l\'agenda Google impossible.');
        }

        return collect($res->json('calendars.primary.busy', []))
            ->map(fn ($b) => [Carbon::parse($b['start']), Carbon::parse($b['end'])])
            ->all();
    }

    public function createEvent(Admin $admin, Appointment $appointment, string $title, string $description, bool $withMeet): array
    {
        $body = [
            'summary' => $title,
            'description' => $description,
            'start' => ['dateTime' => $appointment->starts_at->toIso8601ZuluString()],
            'end' => ['dateTime' => $appointment->ends_at->toIso8601ZuluString()],
            'attendees' => [['email' => $appointment->email, 'displayName' => $appointment->name]],
        ];
        if ($withMeet) {
            $body['conferenceData'] = ['createRequest' => [
                'requestId' => (string) Str::uuid(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
            ]];
        }

        $res = Http::withToken($this->accessToken($admin))
            ->post('https://www.googleapis.com/calendar/v3/calendars/primary/events?conferenceDataVersion=1&sendUpdates=all', $body);
        if (! $res->successful()) {
            throw new RuntimeException('Création de l\'événement Google impossible.');
        }

        return ['event_id' => $res->json('id'), 'meeting_url' => $res->json('hangoutLink')];
    }

    public function deleteEvent(Admin $admin, string $eventId): void
    {
        Http::withToken($this->accessToken($admin))
            ->delete("https://www.googleapis.com/calendar/v3/calendars/primary/events/{$eventId}?sendUpdates=all");
    }

    private function accessToken(Admin $admin): string
    {
        if (! $admin->google_refresh_token) {
            throw new RuntimeException('Agenda Google non connecté.');
        }
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $admin->google_refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (! $res->ok()) {
            throw new RuntimeException('Accès à l\'agenda Google expiré : reconnectez-le.');
        }

        return $res->json('access_token');
    }
}
