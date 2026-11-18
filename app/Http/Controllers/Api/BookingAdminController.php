<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BookingService;
use App\Models\Cabinet;
use App\Services\Booking\AppointmentScheduler;
use App\Services\Calendar\CalendarGateway;
use App\Services\MandateService;
use App\Support\Roles;
use App\Support\Tenancy\CabinetRule;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Réservation en ligne côté cabinet : réglages et page publique, prestations,
 * disponibilités de chaque membre, rendez-vous, connexion Google Agenda.
 */
class BookingAdminController extends Controller
{
    public function __construct(private CalendarGateway $calendar) {}

    /** Vue d'ensemble de l'écran « Rendez-vous ». */
    public function overview(Request $request)
    {
        $admin = $request->user('admin');
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());

        return response()->json(['success' => true, 'data' => [
            'settings' => $this->settingsPayload($cabinet),
            'can_manage' => in_array($admin->role, Roles::MANAGERS, true),
            'services' => BookingService::with('host:id,name')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($s) => $this->servicePayload($s))->values(),
            'staff' => Admin::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'email' => $a->email,
            ])->values(),
            'meeting_modes' => collect(BookingService::MEETING_MODES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'google' => [
                'available' => $this->calendar->isConfigured(),
                'connected' => (bool) $admin->google_refresh_token,
                'email' => $admin->google_calendar_email,
            ],
        ]]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'booking_enabled' => ['required', 'boolean'],
            'booking_intro' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['required', 'timezone:all'],
            'mandate_text' => ['nullable', 'string', 'max:20000'],
        ]);

        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $cabinet->fill($data)->save();

        return response()->json(['success' => true, 'data' => $this->settingsPayload($cabinet)]);
    }

    public function storeService(Request $request)
    {
        $service = BookingService::create($this->validateService($request));

        return response()->json(['success' => true, 'data' => $this->servicePayload($service->load('host:id,name'))], 201);
    }

    public function updateService(Request $request, int $id)
    {
        $service = BookingService::findOrFail($id);
        $service->fill($this->validateService($request))->save();

        return response()->json(['success' => true, 'data' => $this->servicePayload($service->load('host:id,name'))]);
    }

    /** Une prestation déjà réservée est désactivée plutôt que supprimée (historique). */
    public function destroyService(int $id)
    {
        $service = BookingService::findOrFail($id);
        if (Appointment::where('booking_service_id', $service->id)->exists()) {
            $service->forceFill(['is_active' => false])->save();

            return response()->json(['success' => true, 'message' => 'Prestation désactivée (elle a déjà des rendez-vous).']);
        }
        $service->delete();

        return response()->json(['success' => true, 'message' => 'Prestation supprimée.']);
    }

    /** Disponibilités hebdomadaires : les siennes, ou celles d'un collègue pour un gestionnaire. */
    public function availability(Request $request)
    {
        $adminId = $this->targetAdminId($request);

        return response()->json(['success' => true, 'data' => [
            'admin_id' => $adminId,
            'rules' => AvailabilityRule::where('admin_id', $adminId)->orderBy('weekday')->orderBy('start_time')->get()
                ->map(fn ($r) => ['weekday' => $r->weekday, 'start_time' => substr($r->start_time, 0, 5), 'end_time' => substr($r->end_time, 0, 5)])->values(),
        ]]);
    }

    public function updateAvailability(Request $request)
    {
        $adminId = $this->targetAdminId($request);
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:50'],
            'rules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'rules.*.start_time' => ['required', 'date_format:H:i'],
            'rules.*.end_time' => ['required', 'date_format:H:i'],
        ]);

        foreach ($data['rules'] as $i => $rule) {
            if ($rule['end_time'] <= $rule['start_time']) {
                return response()->json(['success' => false, 'message' => 'Chaque plage doit finir après son début.', 'errors' => ["rules.$i.end_time" => ['Heure de fin invalide.']]], 422);
            }
        }

        DB::transaction(function () use ($adminId, $data) {
            AvailabilityRule::where('admin_id', $adminId)->delete();
            foreach ($data['rules'] as $rule) {
                AvailabilityRule::create(['admin_id' => $adminId] + $rule);
            }
        });

        return $this->availability($request);
    }

    public function appointments(Request $request)
    {
        $scope = $request->query('scope', 'upcoming');
        // Les réservations payantes abandonnées (non réglées après le délai) sont masquées.
        $query = Appointment::with(['service:id,name', 'host:id,name'])
            ->where(fn ($q) => $q->where('status', '!=', 'pending_payment')
                ->orWhere('created_at', '>', now()->subMinutes(Appointment::PAYMENT_HOLD_MINUTES)));
        if ($request->boolean('mine')) {
            $query->where('host_admin_id', $request->user('admin')->id);
        }
        $scope === 'past'
            ? $query->where('starts_at', '<', now())->orderByDesc('starts_at')
            : $query->where('starts_at', '>=', now()->subHours(2))->orderBy('starts_at');

        $tz = Cabinet::find(CurrentCabinet::require())?->timezone ?: 'America/Toronto';

        return response()->json(['success' => true, 'data' => $query->limit(200)->get()->map(fn (Appointment $a) => [
            'id' => $a->id,
            'name' => $a->name,
            'email' => $a->email,
            'phone' => $a->phone,
            'notes' => $a->notes,
            'service' => $a->service?->name,
            'host' => $a->host?->name,
            'host_admin_id' => $a->host_admin_id,
            'client_id' => $a->client_id,
            'invoice_id' => $a->invoice_id,
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'local_label' => $a->starts_at->copy()->setTimezone($tz)->locale('fr_CA')->isoFormat('ddd D MMM YYYY, HH:mm'),
            'status' => $a->status,
            'meeting_mode' => $a->meeting_mode,
            'meeting_url' => $a->meeting_url,
            'in_google' => (bool) $a->google_event_id,
        ])->values()]);
    }

    public function cancelAppointment(int $id, AppointmentScheduler $scheduler)
    {
        $appointment = Appointment::findOrFail($id);
        $scheduler->cancel($appointment, 'staff');

        return response()->json(['success' => true, 'message' => 'Rendez-vous annulé, le client est prévenu.']);
    }

    /** Démarre la connexion Google Agenda de l'admin connecté. */
    public function googleConnect(Request $request)
    {
        if (! $this->calendar->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'La synchronisation Google Agenda n\'est pas encore activée sur la plateforme.'], 422);
        }
        $admin = $request->user('admin');
        $state = Str::random(48);
        $admin->forceFill(['google_oauth_state' => $state])->save();

        return response()->json(['success' => true, 'data' => ['url' => $this->calendar->authorizationUrl($state)]]);
    }

    public function googleDisconnect(Request $request)
    {
        $request->user('admin')->forceFill([
            'google_refresh_token' => null,
            'google_calendar_email' => null,
            'google_oauth_state' => null,
        ])->save();

        return response()->json(['success' => true, 'message' => 'Google Agenda déconnecté.']);
    }

    /** Retour de Google (route publique) : le state désigne l'admin qui a lancé la connexion. */
    public function googleCallback(Request $request)
    {
        $front = rtrim(config('app.frontend_url'), '/') . '/admin/rendez-vous';
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        $admin = strlen($state) === 48
            ? CurrentCabinet::withoutScope(fn () => Admin::acrossCabinets()->where('google_oauth_state', $state)->first())
            : null;
        if (! $admin || $code === '' || $request->has('error')) {
            return redirect()->away("{$front}?google=erreur");
        }

        try {
            $tokens = $this->calendar->exchangeCode($code);
        } catch (\Throwable $e) {
            Log::warning('Connexion Google Agenda échouée', ['admin_id' => $admin->id, 'error' => $e->getMessage()]);

            return redirect()->away("{$front}?google=erreur");
        }

        $admin->forceFill([
            'google_refresh_token' => $tokens['refresh_token'],
            'google_calendar_email' => $tokens['email'] ?? null,
            'google_oauth_state' => null,
        ])->save();

        return redirect()->away("{$front}?google=ok");
    }

    private function validateService(Request $request): array
    {
        $data = $request->validate([
            'host_admin_id' => ['required', CabinetRule::exists('admins')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'between:10,480'],
            'price' => ['required', 'numeric', 'between:0,100000'],
            'taxable' => ['boolean'],
            'meeting_mode' => ['required', Rule::in(array_keys(BookingService::MEETING_MODES))],
            'location' => ['nullable', 'string', 'max:255'],
            'send_mandate' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,1000'],
        ]);
        $data['price_cents'] = (int) round(((float) $data['price']) * 100);
        unset($data['price']);
        $data['sort_order'] ??= 0;

        return $data;
    }

    private function targetAdminId(Request $request): int
    {
        $me = $request->user('admin');
        $target = (int) $request->input('admin_id', $me->id);
        if ($target !== $me->id) {
            abort_unless(in_array($me->role, Roles::MANAGERS, true), 403, 'Seuls les gestionnaires modifient les disponibilités d\'un collègue.');
            Admin::findOrFail($target); // filtré par cabinet
        }

        return $target;
    }

    private function settingsPayload(Cabinet $cabinet): array
    {
        return [
            'booking_enabled' => (bool) $cabinet->booking_enabled,
            'booking_intro' => $cabinet->booking_intro,
            'timezone' => $cabinet->timezone ?: 'America/Toronto',
            'mandate_text' => $cabinet->mandate_text,
            'default_mandate_text' => MandateService::DEFAULT_TEXT,
            'public_url' => rtrim(config('app.frontend_url'), '/') . '/reserver/' . $cabinet->slug,
            'slug' => $cabinet->slug,
        ];
    }

    private function servicePayload(BookingService $s): array
    {
        return [
            'id' => $s->id,
            'host_admin_id' => $s->host_admin_id,
            'host' => $s->host?->name,
            'name' => $s->name,
            'description' => $s->description,
            'duration_minutes' => $s->duration_minutes,
            'price' => $s->price_cents / 100,
            'taxable' => $s->taxable,
            'meeting_mode' => $s->meeting_mode,
            'location' => $s->location,
            'send_mandate' => $s->send_mandate,
            'is_active' => $s->is_active,
            'sort_order' => $s->sort_order,
        ];
    }
}
