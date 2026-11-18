<?php

namespace Tests\Feature;

use App\Mail\InvoiceMail;
use App\Mail\InvoicePaidMail;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\ClientProfileService;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * S6 — Factures clients, taxes, encaissement Stripe Connect, abonnement par siège.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private FakeGateway $gateway;
    private Cabinet $cabinet;
    private Admin $owner;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->gateway = new FakeGateway();
        $this->app->instance(PaymentGateway::class, $this->gateway);

        $this->cabinet = Cabinet::firstOrFail();
        $this->cabinet->update(['province' => 'QC']);
        $this->inCabinet(function () {
            $this->owner = Admin::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'Secret#2026x', 'role' => 'owner', 'is_active' => true]);
            $this->client = Client::create(['first_name' => 'Marie', 'last_name' => 'Tremblay', 'email' => 'marie@example.test']);
        });
        Sanctum::actingAs($this->owner, ['*'], 'admin');
    }

    private function inCabinet(callable $fn): mixed
    {
        return CurrentCabinet::runAs($this->cabinet->id, $fn);
    }

    private function createInvoice(array $lines = null, ?int $clientId = null): int
    {
        return $this->postJson('/api/admin/invoices', [
            'client_id' => $clientId ?? $this->client->id,
            'due_date' => '2026-12-15',
            'lines' => $lines ?? [
                ['description' => 'Honoraires — permis de travail', 'quantity' => 1, 'unit_price' => 1000],
                ['description' => 'Frais gouvernementaux (non taxables)', 'quantity' => 1, 'unit_price' => 255, 'taxable' => false],
            ],
        ])->assertCreated()->json('data.id');
    }

    private function webhook(string $type, array $object, string $signature = 'valid')
    {
        return $this->call('POST', '/api/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], json_encode(['type' => $type, 'object' => $object]));
    }

    public function test_taxes_du_quebec_sur_les_seules_lignes_taxables(): void
    {
        $id = $this->createInvoice();

        $this->getJson("/api/admin/invoices/{$id}")->assertOk()
            ->assertJsonPath('data.subtotal_cents', 125500)
            ->assertJsonPath('data.tax_lines.0.name', 'TPS')
            ->assertJsonPath('data.tax_lines.0.amount_cents', 5000)       // 5 % de 1 000 $
            ->assertJsonPath('data.tax_lines.1.name', 'TVQ')
            ->assertJsonPath('data.tax_lines.1.amount_cents', 9975)       // 9,975 % de 1 000 $
            ->assertJsonPath('data.total_cents', 125500 + 5000 + 9975);
    }

    public function test_la_province_du_client_prime_sur_celle_du_cabinet(): void
    {
        $this->inCabinet(fn () => app(ClientProfileService::class)->save($this->client, null, ['province' => 'Ontario', 'country' => 'Canada']));
        $id = $this->createInvoice([['description' => 'Honoraires', 'quantity' => 2, 'unit_price' => 500]]);

        $this->getJson("/api/admin/invoices/{$id}")
            ->assertJsonPath('data.tax_province', 'ON')
            ->assertJsonPath('data.tax_lines.0.name', 'TVH')
            ->assertJsonPath('data.tax_cents', 13000);
    }

    public function test_numerotation_sequentielle_par_cabinet(): void
    {
        $first = $this->createInvoice();
        $second = $this->createInvoice();
        $year = now()->format('Y');

        $this->assertSame("{$year}-0001", $this->inCabinet(fn () => Invoice::find($first)->number));
        $this->assertSame("{$year}-0002", $this->inCabinet(fn () => Invoice::find($second)->number));

        // Un autre cabinet a sa propre numérotation.
        $other = Cabinet::create(['name' => 'Autre', 'slug' => 'autre']);
        $num = CurrentCabinet::runAs($other->id, function () {
            $c = Client::create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.test']);

            return app(\App\Services\InvoiceService::class)->saveDraft(null, $c, null, [['description' => 'A', 'quantity' => 1, 'unit_price' => 10]], null, null, null)->number;
        });
        $this->assertSame("{$year}-0001", $num);
    }

    public function test_envoi_puis_verrouillage_du_brouillon(): void
    {
        $id = $this->createInvoice();
        $this->postJson("/api/admin/invoices/{$id}/send")->assertOk()->assertJsonPath('email_sent', true);
        Mail::assertSent(InvoiceMail::class, fn ($m) => ! $m->isReceipt && $m->hasTo('marie@example.test'));

        $this->putJson("/api/admin/invoices/{$id}", [
            'client_id' => $this->client->id, 'lines' => [['description' => 'Modif', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(422);
    }

    public function test_paiement_en_ligne_via_stripe_connect_puis_webhook(): void
    {
        $id = $this->createInvoice();
        $this->postJson("/api/admin/invoices/{$id}/send")->assertOk();
        $token = $this->inCabinet(fn () => Invoice::find($id)->public_token);

        // Sans compte Stripe connecté, le client ne peut pas payer en ligne.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/invoices/public/{$token}")->assertOk()->assertJsonPath('data.can_pay_online', false);
        $this->postJson("/api/invoices/public/{$token}/checkout")->assertStatus(422);

        // Le propriétaire connecte le compte Stripe du cabinet.
        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson('/api/admin/billing/connect')->assertOk()->assertJsonPath('data.url', 'https://connect.stripe.test/onboarding/acct_fake_' . $this->cabinet->id);
        $this->postJson('/api/admin/billing/connect/refresh')->assertOk()->assertJsonPath('data.charges_enabled', true);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/invoices/public/{$token}")->assertJsonPath('data.can_pay_online', true);
        $this->postJson("/api/invoices/public/{$token}/checkout")->assertOk()->assertJsonPath('data.url', "https://checkout.stripe.test/cs_fake_{$id}");
        $this->assertSame(140475, $this->gateway->called('createInvoiceCheckout')[0]['args'][1]);

        // Une signature invalide est refusée ; la bonne marque la facture payée (une seule fois).
        $object = ['metadata' => ['kind' => 'invoice', 'invoice_id' => (string) $id, 'cabinet_id' => (string) $this->cabinet->id], 'payment_status' => 'paid', 'payment_intent' => 'pi_123'];
        $this->webhook('checkout.session.completed', $object, 'forged')->assertStatus(400);
        $this->webhook('checkout.session.completed', $object)->assertOk();
        $this->webhook('checkout.session.completed', $object)->assertOk();

        $invoice = $this->inCabinet(fn () => Invoice::find($id));
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('card', $invoice->payment_method);
        Mail::assertSent(InvoicePaidMail::class, 1);
        $this->assertSame(1, $this->inCabinet(fn () => AdminNotification::where('type', 'invoice_paid')->count()));
    }

    public function test_paiement_note_a_la_main_et_annulation(): void
    {
        $paid = $this->createInvoice();
        $this->postJson("/api/admin/invoices/{$paid}/mark-paid", ['method' => 'transfer', 'reference' => 'Interac 123'])->assertOk();
        $this->postJson("/api/admin/invoices/{$paid}/void")->assertStatus(422); // payée : pas d'annulation ici
        Mail::assertSent(InvoicePaidMail::class);

        $draft = $this->createInvoice();
        $this->postJson("/api/admin/invoices/{$draft}/void")->assertOk();
        $this->assertSame('void', $this->inCabinet(fn () => Invoice::find($draft)->status));
    }

    public function test_le_client_voit_ses_factures_emises_seulement(): void
    {
        $draft = $this->createInvoice();
        $sent = $this->createInvoice();
        $this->postJson("/api/admin/invoices/{$sent}/send")->assertOk();

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, ['*'], 'client');
        $list = $this->getJson('/api/client/invoices')->assertOk()->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($sent, $list[0]['id']);
        $this->assertNotContains($draft, array_column($list, 'id'));
    }

    public function test_abonnement_par_siege_essai_souscription_et_synchronisation(): void
    {
        $this->getJson('/api/admin/billing')->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.is_active', true)
            ->assertJsonPath('data.subscription.seats_used', 1);

        // Seul le propriétaire souscrit.
        $admin = $this->inCabinet(fn () => Admin::create(['name' => 'Ad', 'email' => 'ad@example.test', 'password' => 'Secret#2026x', 'role' => 'admin', 'is_active' => true]));
        Sanctum::actingAs($admin, ['*'], 'admin');
        $this->postJson('/api/admin/billing/subscribe')->assertForbidden();

        Sanctum::actingAs($this->owner, ['*'], 'admin');
        $this->postJson('/api/admin/billing/subscribe')->assertOk()->assertJsonPath('data.url', 'https://checkout.stripe.test/cs_sub_' . $this->cabinet->id);
        $this->assertSame(2, $this->gateway->called('createSubscriptionCheckout')[0]['args'][1]); // 2 comptes actifs

        // Stripe confirme l'abonnement.
        $this->webhook('customer.subscription.updated', [
            'id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'current_period_end' => now()->addMonth()->timestamp,
            'metadata' => ['cabinet_id' => (string) $this->cabinet->id], 'items' => ['data' => [['quantity' => 2]]],
        ])->assertOk();
        $this->getJson('/api/admin/billing')->assertJsonPath('data.subscription.status', 'active')->assertJsonPath('data.subscription.subscribed_seats', 2);

        // Désactiver un membre ajuste la quantité de l'abonnement.
        $this->putJson("/api/admin/team/{$admin->id}", ['is_active' => false])->assertOk();
        $this->assertSame([$this->cabinet->id, 1], $this->gateway->called('updateSubscriptionSeats')[0]['args']);

        // Résiliation.
        $this->webhook('customer.subscription.deleted', ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'metadata' => ['cabinet_id' => (string) $this->cabinet->id]])->assertOk();
        $this->assertSame('canceled', $this->cabinet->fresh()->subscription_status);
        $this->assertFalse($this->cabinet->fresh()->hasActiveSubscription());
    }

    public function test_sans_cle_stripe_les_paiements_en_ligne_sont_indisponibles(): void
    {
        $this->app->instance(PaymentGateway::class, new \App\Services\Payments\UnconfiguredGateway());

        $this->getJson('/api/admin/billing')->assertJsonPath('data.stripe_configured', false);
        $this->postJson('/api/admin/billing/subscribe')->assertStatus(422)
            ->assertJsonFragment(['message' => 'Les paiements en ligne ne sont pas encore configurés sur la plateforme (clés Stripe manquantes).']);
    }
}
