<?php

namespace Tests\Feature;

use App\Mail\AppointmentMail;
use App\Mail\InvoiceMail;
use App\Mail\MandateMail;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\Appointment;
use App\Models\AvailabilityRule;
use App\Models\BookingService;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Mandate;
use App\Services\Calendar\CalendarGateway;
use App\Services\Calendar\FakeCalendarGateway;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\Tenancy\CurrentCabinet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S7 — Réservation en ligne (prestations, disponibilités, paiement),
 * Google Agenda + Meet, mandat de représentation signé en ligne.
 *
 * Horloge figée au lundi 23 novembre 2026, 6 h à Toronto (11 h UTC).
 * L'hôte est disponible le mardi de 9 h à 12 h (14 h – 17 h UTC).
 */
class BookingAndMandateTest extends TestCase
{
    use RefreshDatabase;

    private FakeCalendarGateway $calendar;
    private FakeGateway $payments;
    private Cabinet $cabinet;
    private Admin $owner;
    private Admin $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CarbonImmutable::setTestNow('2026-11-23 11:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-11-23 11:00:00');

        $this->calendar = new FakeCalendarGateway();
        $this->payments = new FakeGateway();
        $this->app->instance(CalendarGateway::class, $this->calendar);
        $this->app->instance(PaymentGateway::class, $this->payments);

        $this->cabinet = Cabinet::firstOrFail();
        $this->cabinet->update(['province' => 'QC', 'booking_enabled' => true, 'timezone' => 'America/Toronto']);
        $this->inCabinet(function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->assistant = Admin::create(['name' => 'Adjoint', 'email' => 'adj@example.test', 'password' => 'Secret#2026x', 'role' => 'assistant', 'is_active' => true]);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function inCabinet(callable $fn): mixed
    {
        return CurrentCabinet::runAs($this->cabinet->id, $fn);
    }

    private function asGuest(): void
    {
        $this->app['auth']->forgetGuards();
        CurrentCabinet::clear();
    }

    /** Prestation de 60 min animée par le propriétaire, disponible le mardi 9 h – 12 h. */
    private function service(array $overrides = []): int
    {
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->putJson('/api/admin/booking/availability', ['rules' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'], // lundi : trop tôt (préavis de 12 h)
            ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '12:00'],
        ]])->assertOk();

        return $this->postJson('/api/admin/booking/services', array_merge([
            'host_admin_id' => $this->owner->id,
            'name' => 'Consultation initiale',
            'duration_minutes' => 60,
            'price' => 0,
            'meeting_mode' => 'video',
        ], $overrides))->assertCreated()->json('data.id');
    }

    private function slug(): string
    {
        return $this->cabinet->slug;
    }

    private function book(int $serviceId, string $start, string $email = 'marie@example.test')
    {
        return $this->postJson("/api/booking/{$this->slug()}/appointments", [
            'service_id' => $serviceId, 'start' => $start,
            'name' => 'Marie Tremblay', 'email' => $email, 'phone' => '514-555-0000', 'consent' => true,
        ]);
    }

    public function test_creneaux_selon_disponibilites_preavis_et_agenda_google(): void
    {
        $id = $this->service();
        $this->owner->forceFill(['google_refresh_token' => 'refresh-x'])->save();
        $this->calendar->busy = [[CarbonImmutable::parse('2026-11-24 15:00:00'), CarbonImmutable::parse('2026-11-24 15:30:00')]];

        $this->asGuest();
        $this->getJson("/api/booking/{$this->slug()}")->assertOk()
            ->assertJsonPath('data.services.0.name', 'Consultation initiale');

        $slots = $this->getJson("/api/booking/{$this->slug()}/slots?service_id={$id}&from=2026-11-23&days=7")->assertOk()->json('data.slots');
        $this->assertSame(['2026-11-24T14:00:00Z', '2026-11-24T16:00:00Z'], array_column($slots, 'start'));
        $this->assertSame('09:00', $slots[0]['time']);
    }

    public function test_reservation_gratuite_confirmee_avec_meet_et_double_reservation_refusee(): void
    {
        $id = $this->service();
        $this->owner->forceFill(['google_refresh_token' => 'refresh-x'])->save();

        $this->asGuest();
        $token = $this->book($id, '2026-11-24T14:00:00Z')->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.checkout_url', null)
            ->json('data.token');

        // Le même créneau n'est plus proposé ni réservable.
        $this->book($id, '2026-11-24T14:00:00Z', 'autre@example.test')->assertStatus(409);
        // Un horaire hors disponibilités est refusé.
        $this->book($id, '2026-11-24T20:00:00Z', 'autre@example.test')->assertStatus(409);

        $this->getJson("/api/appointments/public/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.meeting_url', 'https://meet.google.test/abc-defg-hij')
            ->assertJsonPath('data.can_cancel', true);

        $this->assertCount(1, $this->calendar->created);
        $this->assertTrue($this->calendar->created[0]['meet']);
        Mail::assertQueued(AppointmentMail::class, fn ($m) => $m->kind === 'confirmed' && $m->hasTo('marie@example.test') && count($m->attachments()) === 1);
        $this->inCabinet(function () {
            $this->assertSame(1, Client::where('email', 'marie@example.test')->count());
            $this->assertSame(1, AdminNotification::where('type', 'appointment_booked')->count());
        });
    }

    public function test_reservation_payante_attend_le_paiement_stripe_puis_se_confirme(): void
    {
        $id = $this->service(['price' => 150, 'taxable' => true]);
        $this->cabinet->forceFill(['stripe_account_id' => 'acct_1', 'stripe_charges_enabled' => true])->save();

        $this->asGuest();
        $res = $this->book($id, '2026-11-24T15:00:00Z')->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment');
        $this->assertStringStartsWith('https://checkout.stripe.test/', $res->json('data.checkout_url'));
        Mail::assertNotQueued(AppointmentMail::class);

        // Créneau retenu pendant le paiement.
        $this->book($id, '2026-11-24T15:00:00Z', 'autre@example.test')->assertStatus(409);

        $invoice = $this->inCabinet(fn () => Invoice::firstOrFail());
        $this->assertSame(17246, $invoice->total_cents); // 150 $ + TPS + TVQ
        $this->call('POST', '/api/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 'valid', 'CONTENT_TYPE' => 'application/json'], json_encode([
            'type' => 'checkout.session.completed',
            'object' => ['metadata' => ['kind' => 'invoice', 'invoice_id' => (string) $invoice->id, 'cabinet_id' => (string) $this->cabinet->id], 'payment_status' => 'paid', 'payment_intent' => 'pi_1'],
        ]))->assertOk();

        $this->assertSame('confirmed', $this->inCabinet(fn () => Appointment::firstOrFail()->status));
        Mail::assertQueued(AppointmentMail::class, fn ($m) => $m->kind === 'confirmed');
    }

    public function test_reservation_payante_sans_paiement_en_ligne_confirmee_et_facturee(): void
    {
        $id = $this->service(['price' => 100]);

        $this->asGuest();
        $this->book($id, '2026-11-24T14:00:00Z')->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.checkout_url', null);
        Mail::assertQueued(InvoiceMail::class);
        Mail::assertQueued(AppointmentMail::class);
        $this->assertSame('sent', $this->inCabinet(fn () => Invoice::firstOrFail()->status));
    }

    public function test_annulation_par_le_client_retire_l_evenement_google_et_annule_la_facture(): void
    {
        $id = $this->service(['price' => 100]);
        $this->owner->forceFill(['google_refresh_token' => 'refresh-x'])->save();

        $this->asGuest();
        $token = $this->book($id, '2026-11-24T14:00:00Z')->json('data.token');
        $this->postJson("/api/appointments/public/{$token}/cancel")->assertOk();
        $this->postJson("/api/appointments/public/{$token}/cancel")->assertStatus(422);

        $this->assertSame(['evt_1'], $this->calendar->deleted);
        Mail::assertQueued(AppointmentMail::class, fn ($m) => $m->kind === 'cancelled');
        $this->inCabinet(function () {
            $this->assertSame('cancelled', Appointment::firstOrFail()->status);
            $this->assertSame('void', Invoice::firstOrFail()->status);
        });

        // Le créneau est de nouveau libre.
        $this->book($id, '2026-11-24T14:00:00Z', 'autre@example.test')->assertCreated();
    }

    public function test_annulation_en_ligne_refusee_moins_de_24h_avant(): void
    {
        $id = $this->service();
        $this->asGuest();
        $token = $this->book($id, '2026-11-24T14:00:00Z')->json('data.token');

        CarbonImmutable::setTestNow('2026-11-23 15:00:00');
        \Illuminate\Support\Carbon::setTestNow('2026-11-23 15:00:00');
        $this->getJson("/api/appointments/public/{$token}")->assertJsonPath('data.can_cancel', false);
        $this->postJson("/api/appointments/public/{$token}/cancel")->assertStatus(422);
    }

    public function test_mandat_envoye_signe_et_trace(): void
    {
        $client = $this->inCabinet(fn () => Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test']));

        $this->getJson("/api/admin/mandates/preview?client_id={$client->id}")->assertOk()
            ->assertJsonPath('data.title', 'Mandat de représentation');
        $this->postJson('/api/admin/mandates', ['client_id' => $client->id])->assertCreated();
        Mail::assertQueued(MandateMail::class, fn ($m) => ! $m->signedCopy && $m->hasTo('marie@example.test'));
        $mandate = $this->inCabinet(fn () => Mandate::firstOrFail());
        $this->assertStringContainsString('Marie Tremblay', $mandate->body);

        $this->asGuest();
        $this->getJson("/api/mandates/public/{$mandate->public_token}")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/mandates/public/{$mandate->public_token}/sign", ['signed_name' => 'Marie Tremblay'])->assertStatus(422);
        $this->postJson("/api/mandates/public/{$mandate->public_token}/sign", ['signed_name' => 'Marie Tremblay', 'consent' => true], ['User-Agent' => 'TestBrowser/1.0'])
            ->assertOk()->assertJsonPath('data.status', 'signed');
        $this->postJson("/api/mandates/public/{$mandate->public_token}/sign", ['signed_name' => 'Marie Tremblay', 'consent' => true])->assertStatus(422);

        $mandate = $this->inCabinet(fn () => Mandate::firstOrFail());
        $this->assertSame('127.0.0.1', $mandate->signed_ip);
        $this->assertSame('TestBrowser/1.0', $mandate->signed_user_agent);
        $this->assertSame(hash('sha256', $mandate->body), $mandate->body_hash);
        Mail::assertQueued(MandateMail::class, fn ($m) => $m->signedCopy);
        $this->assertSame(1, $this->inCabinet(fn () => AdminNotification::where('type', 'mandate_signed')->count()));

        // Un mandat signé ne s'annule pas depuis l'interface.
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson("/api/admin/mandates/{$mandate->id}/void")->assertStatus(422);
    }

    public function test_texte_modifie_apres_envoi_signature_refusee(): void
    {
        $client = $this->inCabinet(fn () => Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test']));
        $this->postJson('/api/admin/mandates', ['client_id' => $client->id])->assertCreated();
        $mandate = $this->inCabinet(fn () => tap(Mandate::firstOrFail())->forceFill(['body' => 'Texte altéré'])->save());
        $token = $this->inCabinet(fn () => Mandate::firstOrFail()->public_token);

        $this->asGuest();
        $this->postJson("/api/mandates/public/{$token}/sign", ['signed_name' => 'Marie Tremblay', 'consent' => true])->assertStatus(422);
    }

    public function test_prestation_avec_mandat_l_envoie_apres_la_reservation(): void
    {
        $id = $this->service(['send_mandate' => true]);
        $this->asGuest();
        $this->book($id, '2026-11-24T14:00:00Z')->assertCreated();

        $this->inCabinet(fn () => $this->assertSame(1, Mandate::whereNotNull('appointment_id')->count()));
        Mail::assertQueued(MandateMail::class);
    }

    public function test_connexion_google_agenda_par_state(): void
    {
        $url = $this->postJson('/api/admin/google/connect')->assertOk()->json('data.url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->asGuest();
        $this->get('/api/google/callback?state=mauvais&code=abc')->assertRedirectContains('google=erreur');
        $this->get("/api/google/callback?state={$q['state']}&code=abc")->assertRedirectContains('google=ok');

        $admin = $this->owner->fresh();
        $this->assertSame('refresh-abc', $admin->google_refresh_token);
        $this->assertSame('agenda@example.test', $admin->google_calendar_email);
        $this->assertNull($admin->google_oauth_state);
        $this->assertArrayNotHasKey('google_refresh_token', $admin->toArray());
    }

    public function test_droits_et_isolation(): void
    {
        $id = $this->service();

        // L'adjoint gère ses propres disponibilités, pas celles d'un collègue ni les prestations.
        Sanctum::actingAs($this->assistant, ['*'], 'admin');
        $this->putJson('/api/admin/booking/availability', ['rules' => [['weekday' => 3, 'start_time' => '13:00', 'end_time' => '15:00']]])->assertOk();
        $this->putJson('/api/admin/booking/availability', ['admin_id' => $this->owner->id, 'rules' => []])->assertForbidden();
        $this->postJson('/api/admin/booking/services', ['host_admin_id' => $this->assistant->id, 'name' => 'X', 'duration_minutes' => 30, 'price' => 0, 'meeting_mode' => 'phone'])->assertForbidden();
        $this->putJson('/api/admin/booking/availability', ['rules' => [['weekday' => 3, 'start_time' => '15:00', 'end_time' => '13:00']]])->assertStatus(422);

        $this->asGuest();
        $token = $this->book($id, '2026-11-24T14:00:00Z')->json('data.token');

        // Un autre cabinet ne voit ni le rendez-vous, ni la prestation.
        $other = Cabinet::create(['name' => 'Autre', 'slug' => 'autre', 'booking_enabled' => true]);
        $intruder = CurrentCabinet::runAs($other->id, fn () => Admin::create(['name' => 'I', 'email' => 'i@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]));
        Sanctum::actingAs($intruder, ['*'], 'admin');
        $this->getJson('/api/admin/booking/appointments')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson("/api/admin/booking/services/{$id}", ['host_admin_id' => $intruder->id, 'name' => 'X', 'duration_minutes' => 30, 'price' => 0, 'meeting_mode' => 'phone'])->assertNotFound();
        $this->asGuest();
        $this->getJson("/api/booking/autre/slots?service_id={$id}")->assertNotFound();

        // Réservation fermée : page introuvable.
        $this->cabinet->update(['booking_enabled' => false]);
        $this->getJson("/api/booking/{$this->slug()}")->assertNotFound();
        // Le lien du rendez-vous reste valable.
        $this->getJson("/api/appointments/public/{$token}")->assertOk();
    }
}
