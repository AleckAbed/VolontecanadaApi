<?php

namespace Tests\Feature;

use App\Mail\AppointmentMail;
use App\Models\Admin;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Dossier;
use App\Services\Calendar\CalendarGateway;
use App\Services\Calendar\FakeCalendarGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\Tenancy\CurrentCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rendez-vous : déplacement en ligne (client et personnel) et suivi dans le dossier (3.11).
 * Horloge : lundi 23 novembre 2026, 11 h UTC. Hôte disponible le mardi 9 h – 12 h à Toronto (14 h – 17 h UTC).
 */
class AppointmentFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private Cabinet $cabinet;
    private Admin $owner;
    private FakeCalendarGateway $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-23 11:00:00');
        Carbon::setTestNow('2026-11-23 11:00:00');
        $this->calendar = new FakeCalendarGateway();
        $this->app->instance(CalendarGateway::class, $this->calendar);
        $this->app->instance(PaymentGateway::class, new FakeGateway());

        $this->cabinet = Cabinet::firstOrFail();
        $this->cabinet->update(['booking_enabled' => true, 'timezone' => 'America/Toronto']);
        $this->owner = CurrentCabinet::runAs($this->cabinet->id, fn () => Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
    }

    /** Prestation gratuite de 60 min, réservée mardi 9 h (14 h UTC) ; renvoie le jeton du rendez-vous. */
    private function booked(): string
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson('/api/admin/booking/availability', ['rules' => [['weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00']]])->assertOk();
        $serviceId = $this->postJson('/api/admin/booking/services', [
            'host_admin_id' => $this->owner->id, 'name' => 'Consultation', 'duration_minutes' => 60, 'price' => 0, 'meeting_mode' => 'video',
        ])->assertCreated()->json('data.id');

        $this->asGuest();

        return $this->postJson("/api/booking/{$this->cabinet->slug}/appointments", [
            'service_id' => $serviceId, 'start' => '2026-11-24T14:00:00Z', 'name' => 'Marie Tremblay', 'email' => 'marie@example.test', 'consent' => true,
        ])->assertCreated()->json('data.token');
    }

    public function test_le_client_deplace_son_rendez_vous_deux_fois_au_plus(): void
    {
        $token = $this->booked();
        $this->getJson("/api/appointments/public/{$token}")->assertJsonPath('data.can_reschedule', true)->assertJsonPath('data.reschedules_left', 2);

        // Le créneau actuel reste proposé (le rendez-vous ne se bloque pas lui-même).
        $slots = collect($this->getJson("/api/appointments/public/{$token}/slots?from=2026-11-24&days=1")->assertOk()->json('data.slots'))->pluck('start');
        $this->assertEquals(['2026-11-24T14:00:00Z', '2026-11-24T15:00:00Z', '2026-11-24T16:00:00Z'], $slots->all());

        $this->postJson("/api/appointments/public/{$token}/reschedule", ['start' => '2026-11-24T15:00:00Z'])->assertOk();
        $a = Appointment::acrossCabinets()->where('public_token', $token)->first();
        $this->assertSame('2026-11-24 15:00:00', $a->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-24 16:00:00', $a->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, $a->reschedule_count);
        $this->assertNull($a->reminder_sent_at);
        Mail::assertQueued(AppointmentMail::class, fn ($m) => $m->kind === 'rescheduled' && str_contains($m->attachments()[0]->as ?? 'rendez-vous.ics', 'ics'));

        // Un créneau pris par un autre rendez-vous est refusé.
        CurrentCabinet::runAs($this->cabinet->id, fn () => Appointment::create([
            'booking_service_id' => $a->booking_service_id, 'host_admin_id' => $this->owner->id, 'name' => 'Autre', 'email' => 'autre@example.test',
            'starts_at' => '2026-11-24 16:00:00', 'ends_at' => '2026-11-24 17:00:00', 'status' => 'confirmed', 'meeting_mode' => 'video', 'public_token' => 'autre-jeton',
        ]));
        $this->asGuest();
        $this->postJson("/api/appointments/public/{$token}/reschedule", ['start' => '2026-11-24T16:00:00Z'])->assertStatus(409);

        $this->postJson("/api/appointments/public/{$token}/reschedule", ['start' => '2026-11-24T14:00:00Z'])->assertOk();
        $this->getJson("/api/appointments/public/{$token}")->assertJsonPath('data.can_reschedule', false)->assertJsonPath('data.reschedules_left', 0);
        $this->postJson("/api/appointments/public/{$token}/reschedule", ['start' => '2026-11-24T15:00:00Z'])->assertStatus(422);
    }

    public function test_pas_de_deplacement_en_ligne_a_moins_de_24_h(): void
    {
        $token = $this->booked();
        Carbon::setTestNow('2026-11-23 15:00:00');
        CarbonImmutable::setTestNow('2026-11-23 15:00:00');
        $this->getJson("/api/appointments/public/{$token}")->assertJsonPath('data.can_reschedule', false);
        $this->postJson("/api/appointments/public/{$token}/reschedule", ['start' => '2026-11-24T15:00:00Z'])->assertStatus(422);

        // Le personnel, lui, peut encore le déplacer.
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $id = Appointment::acrossCabinets()->where('public_token', $token)->value('id');
        $this->postJson("/api/admin/booking/appointments/{$id}/reschedule", ['start' => '2026-11-24T16:00:00Z'])->assertOk();
    }

    public function test_suivi_du_rendez_vous_dans_le_dossier(): void
    {
        $token = $this->booked();
        $a = Appointment::acrossCabinets()->where('public_token', $token)->first();
        [$dossier, $other] = CurrentCabinet::runAs($this->cabinet->id, function () use ($a) {
            $autre = Client::create(['first_name' => 'Autre', 'last_name' => 'Client', 'email' => 'x@example.test']);

            return [
                Dossier::create(['client_id' => $a->client_id, 'scope' => 'client', 'name' => 'Résidence permanente']),
                Dossier::create(['client_id' => $autre->id, 'scope' => 'client', 'name' => 'Autre dossier']),
            ];
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');

        // Lien au dossier possible tout de suite, issue seulement une fois passé.
        $this->putJson("/api/admin/booking/appointments/{$a->id}/follow-up", ['dossier_id' => $other->id])->assertStatus(422);
        $this->putJson("/api/admin/booking/appointments/{$a->id}/follow-up", ['dossier_id' => $dossier->id, 'outcome' => 'held'])->assertStatus(422);
        $this->putJson("/api/admin/booking/appointments/{$a->id}/follow-up", ['dossier_id' => $dossier->id])->assertOk();

        Carbon::setTestNow('2026-11-24 16:00:00');
        CarbonImmutable::setTestNow('2026-11-24 16:00:00');
        $bucket = collect($this->getJson('/api/admin/actions')->assertOk()->json('data.buckets'))
            ->firstWhere('key', 'appointments_to_document');
        $this->assertSame(1, $bucket['count'] ?? null);

        $this->putJson("/api/admin/booking/appointments/{$a->id}/follow-up", [
            'dossier_id' => $dossier->id, 'outcome' => 'held', 'staff_notes' => 'Admissible à Entrée express ; liste des pièces envoyée.',
        ])->assertOk();

        $this->getJson("/api/admin/dossiers/{$dossier->id}/appointments")->assertOk()
            ->assertJsonPath('data.0.linked', true)
            ->assertJsonPath('data.0.outcome', 'held')
            ->assertJsonPath('data.0.staff_notes', 'Admissible à Entrée express ; liste des pièces envoyée.');
        $this->assertNotNull($a->fresh()->outcome_at);
    }
}
