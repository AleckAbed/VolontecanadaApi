<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BookingService;
use App\Models\Cabinet;
use App\Models\Invoice;
use App\Services\Booking\AppointmentScheduler;
use App\Services\Booking\SlotFinder;
use App\Support\Tenancy\CurrentCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Page publique de réservation d'un cabinet (/reserver/{slug}) et gestion
 * d'un rendez-vous par son lien (/rendez-vous/{token}).
 */
class PublicBookingController extends Controller
{
    /** Un client peut annuler lui-même jusqu'à ce délai avant le rendez-vous. */
    public const CANCEL_NOTICE_HOURS = 24;

    public function show(string $slug)
    {
        $cabinet = $this->cabinet($slug);
        $hostsWithRules = AvailabilityRule::distinct()->pluck('admin_id');

        $services = BookingService::where('is_active', true)
            ->whereIn('host_admin_id', $hostsWithRules)
            ->with('host:id,name')
            ->orderBy('sort_order')->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => [
            'cabinet' => ['name' => $cabinet->name, 'intro' => $cabinet->booking_intro, 'timezone' => $cabinet->timezone ?: 'America/Toronto', 'branding' => $cabinet->branding()],
            'services' => $services->map(fn (BookingService $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'duration_minutes' => $s->duration_minutes,
                'price' => $s->price_cents / 100,
                'taxable' => $s->taxable,
                'meeting_mode' => $s->meeting_mode,
                'meeting_mode_label' => BookingService::MEETING_MODES[$s->meeting_mode] ?? '',
                'location' => $s->meeting_mode === 'in_person' ? $s->location : null,
                'host' => $s->host?->name,
            ])->values(),
        ]]);
    }

    public function slots(Request $request, string $slug, SlotFinder $finder)
    {
        $cabinet = $this->cabinet($slug);
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,14'],
        ]);
        $service = BookingService::where('is_active', true)->findOrFail($data['service_id']);

        $tz = $cabinet->timezone ?: 'America/Toronto';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $tz)->startOfDay() : $today;
        if ($from->lt($today)) {
            $from = $today;
        }
        $to = $from->addDays(($data['days'] ?? 7) - 1);

        $slots = collect($finder->slots($cabinet, $service, $from, $to))->map(function (CarbonImmutable $s) use ($tz) {
            $local = $s->setTimezone($tz);

            return ['start' => $s->toIso8601ZuluString(), 'date' => $local->toDateString(), 'time' => $local->format('H:i')];
        });

        return response()->json(['success' => true, 'data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => $tz,
            'max_date' => $today->addDays(SlotFinder::MAX_DAYS_AHEAD)->toDateString(),
            'slots' => $slots->values(),
        ]]);
    }

    public function book(Request $request, string $slug, AppointmentScheduler $scheduler)
    {
        $cabinet = $this->cabinet($slug);
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'start' => ['required', 'date'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Veuillez accepter que le cabinet utilise ces renseignements pour organiser le rendez-vous.',
        ]);
        $service = BookingService::where('is_active', true)->findOrFail($data['service_id']);

        try {
            $result = $scheduler->book($cabinet, $service, CarbonImmutable::parse($data['start'])->utc(), [
                'name' => trim($data['name']),
                'email' => mb_strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => [
            'token' => $result['appointment']->public_token,
            'status' => $result['appointment']->fresh()->status,
            'checkout_url' => $result['checkout_url'],
        ]], 201);
    }

    /** Rendez-vous consulté par son lien (le jeton désigne le cabinet : cabinet.code:appointment). */
    public function manage(string $token)
    {
        $a = Appointment::with('service')->where('public_token', $token)->firstOrFail();
        $cabinet = Cabinet::find($a->cabinet_id);
        $tz = $cabinet?->timezone ?: 'America/Toronto';
        $invoice = $a->invoice_id ? Invoice::find($a->invoice_id) : null;

        return response()->json(['success' => true, 'data' => [
            'cabinet' => $cabinet?->name,
            'branding' => $cabinet?->branding(),
            'booking_url' => $cabinet?->booking_enabled ? '/reserver/' . $cabinet->slug : null,
            'service' => $a->service?->name,
            'name' => $a->name,
            'status' => $a->status === 'pending_payment' && $a->created_at->lt(now()->subMinutes(Appointment::PAYMENT_HOLD_MINUTES))
                ? 'expired' : $a->status,
            'starts_at' => $a->starts_at->toIso8601String(),
            'local_label' => $a->starts_at->copy()->setTimezone($tz)->locale('fr_CA')->isoFormat('dddd D MMMM YYYY [à] HH:mm'),
            'timezone' => $tz,
            'duration_minutes' => $a->starts_at->diffInMinutes($a->ends_at),
            'meeting_mode' => $a->meeting_mode,
            'meeting_mode_label' => BookingService::MEETING_MODES[$a->meeting_mode] ?? '',
            'meeting_url' => $a->status === 'confirmed' ? $a->meeting_url : null,
            'location' => $a->meeting_mode === 'in_person' ? $a->service?->location : null,
            'invoice' => $invoice && $invoice->status !== 'draft' ? [
                'status' => $invoice->status,
                'total' => $invoice->total_cents / 100,
                'url' => '/facture/' . $invoice->public_token,
            ] : null,
            'can_cancel' => $this->canCancel($a),
            'cancel_notice_hours' => self::CANCEL_NOTICE_HOURS,
            'can_reschedule' => $this->canReschedule($a),
            'reschedules_left' => max(0, Appointment::MAX_CLIENT_RESCHEDULES - $a->reschedule_count),
        ]]);
    }

    public function cancel(string $token, AppointmentScheduler $scheduler)
    {
        $a = Appointment::where('public_token', $token)->firstOrFail();
        if (! $this->canCancel($a)) {
            return response()->json(['success' => false, 'message' => 'Ce rendez-vous ne peut plus être annulé en ligne. Contactez le cabinet.'], 422);
        }
        $scheduler->cancel($a, 'client');

        return response()->json(['success' => true, 'message' => 'Rendez-vous annulé.']);
    }

    private function canCancel(Appointment $a): bool
    {
        return in_array($a->status, ['confirmed', 'pending_payment'], true)
            && $a->starts_at->gt(now()->addHours(self::CANCEL_NOTICE_HOURS));
    }

    /** Cabinet de la page publique : actif et réservation ouverte, sinon 404. */
    private function cabinet(string $slug): Cabinet
    {
        $cabinet = Cabinet::where('slug', $slug)->where('is_active', true)->where('booking_enabled', true)->first();
        abort_unless($cabinet, 404, 'Cette page de réservation n\'existe pas ou n\'est pas ouverte.');
        CurrentCabinet::set($cabinet->id);

        return $cabinet;
    }

    /** Créneaux libres pour déplacer le rendez-vous (même prestation, même hôte). */
    public function rescheduleSlots(Request $request, string $token, SlotFinder $finder)
    {
        $a = Appointment::where('public_token', $token)->firstOrFail();
        if (! $this->canReschedule($a)) {
            return response()->json(['success' => false, 'message' => 'Ce rendez-vous ne peut plus être déplacé en ligne. Contactez le cabinet.'], 422);
        }
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'days' => ['nullable', 'integer', 'between:1,14']]);

        return response()->json(['success' => true, 'data' => $finder->window(
            Cabinet::findOrFail($a->cabinet_id), BookingService::findOrFail($a->booking_service_id), $data['from'] ?? null, $data['days'] ?? null, $a,
        )]);
    }

    public function reschedule(Request $request, string $token, AppointmentScheduler $scheduler)
    {
        $a = Appointment::where('public_token', $token)->firstOrFail();
        if (! $this->canReschedule($a)) {
            return response()->json(['success' => false, 'message' => 'Ce rendez-vous ne peut plus être déplacé en ligne. Contactez le cabinet.'], 422);
        }
        $data = $request->validate(['start' => ['required', 'date']]);
        try {
            $a = $scheduler->reschedule($a, CarbonImmutable::parse($data['start'])->utc(), 'client');
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'message' => 'Rendez-vous déplacé. Une nouvelle invitation vous a été envoyée par courriel.']);
    }

    /** Déplacement en ligne : rendez-vous confirmé, au moins 24 h avant, au plus 2 fois. */
    private function canReschedule(Appointment $a): bool
    {
        return $a->status === 'confirmed'
            && $a->starts_at->gt(now()->addHours(self::CANCEL_NOTICE_HOURS))
            && $a->reschedule_count < Appointment::MAX_CLIENT_RESCHEDULES;
    }
}
