<?php

namespace App\Services\Booking;

use App\Models\Admin;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BookingService;
use App\Models\Cabinet;
use App\Services\Calendar\CalendarGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Créneaux libres d'une prestation :
 *  plages hebdomadaires de l'hôte (heure locale du cabinet)
 *  − rendez-vous déjà pris (y compris réservations payantes en attente < 30 min)
 *  − plages occupées de son Google Agenda
 *  − délai minimal avant le rendez-vous.
 * Tous les créneaux renvoyés sont en UTC.
 */
class SlotFinder
{
    public const MIN_NOTICE_HOURS = 12;
    public const MAX_DAYS_AHEAD = 60;

    public function __construct(private CalendarGateway $calendar) {}

    /** @return array<int, CarbonImmutable> débuts des créneaux libres (UTC) */
    public function slots(Cabinet $cabinet, BookingService $service, CarbonImmutable $fromDay, CarbonImmutable $toDay, ?Appointment $ignore = null): array
    {
        $tz = $cabinet->timezone ?: 'America/Toronto';
        $duration = max(5, $service->duration_minutes);
        $earliest = CarbonImmutable::now('UTC')->addHours(self::MIN_NOTICE_HOURS);
        $latest = CarbonImmutable::now('UTC')->addDays(self::MAX_DAYS_AHEAD);

        $fromLocal = $fromDay->setTimezone($tz)->startOfDay();
        $toLocal = $toDay->setTimezone($tz)->endOfDay();

        $rules = AvailabilityRule::where('admin_id', $service->host_admin_id)->get()->groupBy('weekday');
        if ($rules->isEmpty()) {
            return [];
        }

        $rangeStart = $fromLocal->utc();
        $rangeEnd = $toLocal->utc();
        $busy = Appointment::blocking()
            ->where('host_admin_id', $service->host_admin_id)
            ->where('starts_at', '<', $rangeEnd)
            ->where('ends_at', '>', $rangeStart)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($a) => [CarbonImmutable::parse($a->starts_at), CarbonImmutable::parse($a->ends_at)])
            ->all();

        $host = Admin::find($service->host_admin_id);
        if ($host?->google_refresh_token) {
            try {
                foreach ($this->calendar->busy($host, $rangeStart, $rangeEnd) as [$s, $e]) {
                    $s = CarbonImmutable::parse($s);
                    $e = CarbonImmutable::parse($e);
                    // L'événement Google du rendez-vous déplacé n'occupe pas son propre créneau.
                    if ($ignore && $s->equalTo($ignore->starts_at) && $e->equalTo($ignore->ends_at)) {
                        continue;
                    }
                    $busy[] = [$s, $e];
                }
            } catch (\Throwable $e) {
                // Agenda injoignable : on propose quand même les créneaux (les rendez-vous internes restent bloquants).
                Log::warning('Google Agenda indisponible pour le calcul des créneaux', ['admin_id' => $host->id, 'error' => $e->getMessage()]);
            }
        }

        $slots = [];
        for ($day = $fromLocal; $day->lte($toLocal); $day = $day->addDay()) {
            foreach ($rules->get($day->isoWeekday(), collect()) as $rule) {
                $start = $day->setTimeFromTimeString($rule->start_time);
                $end = $day->setTimeFromTimeString($rule->end_time);
                for ($slot = $start; $slot->addMinutes($duration)->lte($end); $slot = $slot->addMinutes($duration)) {
                    $sUtc = $slot->utc();
                    $eUtc = $sUtc->addMinutes($duration);
                    if ($sUtc->lt($earliest) || $sUtc->gt($latest)) {
                        continue;
                    }
                    if ($this->overlaps($sUtc, $eUtc, $busy)) {
                        continue;
                    }
                    $slots[] = $sUtc;
                }
            }
        }

        usort($slots, fn ($a, $b) => $a <=> $b);

        return array_values(array_unique($slots, SORT_REGULAR));
    }

    public function isAvailable(Cabinet $cabinet, BookingService $service, CarbonImmutable $startUtc, ?Appointment $ignore = null): bool
    {
        foreach ($this->slots($cabinet, $service, $startUtc->subDay(), $startUtc->addDay(), $ignore) as $slot) {
            if ($slot->equalTo($startUtc)) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(CarbonImmutable $s, CarbonImmutable $e, array $busy): bool
    {
        foreach ($busy as [$bs, $be]) {
            if ($s->lt($be) && $e->gt($bs)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Créneaux d'une semaine (ou de $days jours) à partir de $from, au format des pages :
     * ['from', 'to', 'timezone', 'max_date', 'slots' => [['start', 'date', 'time']]].
     */
    public function window(Cabinet $cabinet, BookingService $service, ?string $from, ?int $days, ?Appointment $ignore = null): array
    {
        $tz = $cabinet->timezone ?: 'America/Toronto';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = $from ? CarbonImmutable::createFromFormat('Y-m-d', $from, $tz)->startOfDay() : $today;
        if ($start->lt($today)) {
            $start = $today;
        }
        $end = $start->addDays(($days ?: 7) - 1);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'timezone' => $tz,
            'max_date' => $today->addDays(self::MAX_DAYS_AHEAD)->toDateString(),
            'slots' => collect($this->slots($cabinet, $service, $start, $end, $ignore))->map(function (CarbonImmutable $s) use ($tz) {
                $local = $s->setTimezone($tz);

                return ['start' => $s->toIso8601ZuluString(), 'date' => $local->toDateString(), 'time' => $local->format('H:i')];
            })->values()->all(),
        ];
    }
}
