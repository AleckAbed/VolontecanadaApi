<?php

namespace App\Services\Payments;

use App\Models\Cabinet;
use App\Models\Invoice;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe, avec le compte de la plateforme.
 *
 * Factures clients : charge « destination » (on_behalf_of + transfer_data) :
 * le cabinet est le marchand affiché et reçoit les fonds ; la plateforme peut
 * prélever une commission (STRIPE_PLATFORM_FEE_PERCENT, 0 par défaut).
 */
class StripeGateway implements PaymentGateway
{
    private StripeClient $stripe;

    public function __construct(private string $secret, private ?string $webhookSecret, private ?string $seatPrice, private float $feePercent = 0)
    {
        $this->stripe = new StripeClient($secret);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createConnectedAccount(Cabinet $cabinet, string $email): string
    {
        $account = $this->stripe->accounts->create([
            'type' => 'express',
            'country' => 'CA',
            'email' => $email,
            'business_profile' => ['name' => $cabinet->name],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => ['cabinet_id' => (string) $cabinet->id],
        ]);

        return $account->id;
    }

    public function connectOnboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->stripe->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url;
    }

    public function connectedAccountStatus(string $accountId): array
    {
        $account = $this->stripe->accounts->retrieve($accountId);

        return [
            'charges_enabled' => (bool) $account->charges_enabled,
            'details_submitted' => (bool) $account->details_submitted,
        ];
    }

    public function createInvoiceCheckout(Invoice $invoice, Cabinet $cabinet, string $successUrl, string $cancelUrl): array
    {
        if (! $cabinet->stripe_account_id || ! $cabinet->stripe_charges_enabled) {
            throw new RuntimeException('Le cabinet n\'a pas encore activé les paiements en ligne.');
        }

        $paymentIntent = [
            'on_behalf_of' => $cabinet->stripe_account_id,
            'transfer_data' => ['destination' => $cabinet->stripe_account_id],
            'metadata' => ['kind' => 'invoice', 'invoice_id' => (string) $invoice->id, 'cabinet_id' => (string) $cabinet->id],
            'description' => "Facture {$invoice->number} — {$cabinet->name}",
        ];
        if ($this->feePercent > 0) {
            $paymentIntent['application_fee_amount'] = (int) round($invoice->total_cents * $this->feePercent / 100);
        }

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => $invoice->client?->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'unit_amount' => $invoice->total_cents,
                    'product_data' => ['name' => "Facture {$invoice->number} — {$cabinet->name}"],
                ],
            ]],
            'payment_intent_data' => $paymentIntent,
            'metadata' => ['kind' => 'invoice', 'invoice_id' => (string) $invoice->id, 'cabinet_id' => (string) $cabinet->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'locale' => 'fr-CA',
        ]);

        return ['id' => $session->id, 'url' => $session->url];
    }

    public function createSubscriptionCheckout(Cabinet $cabinet, int $seats, string $email, string $successUrl, string $cancelUrl): array
    {
        if (! $this->seatPrice) {
            throw new RuntimeException('Le prix par siège (STRIPE_PRICE_SEAT) n\'est pas configuré.');
        }

        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $this->seatPrice, 'quantity' => max(1, $seats)]],
            'subscription_data' => ['metadata' => ['cabinet_id' => (string) $cabinet->id]],
            'metadata' => ['kind' => 'subscription', 'cabinet_id' => (string) $cabinet->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'locale' => 'fr-CA',
            'automatic_tax' => ['enabled' => false],
        ];
        $params += $cabinet->stripe_customer_id ? ['customer' => $cabinet->stripe_customer_id] : ['customer_email' => $email];

        $session = $this->stripe->checkout->sessions->create($params);

        return ['id' => $session->id, 'url' => $session->url];
    }

    public function billingPortalUrl(Cabinet $cabinet, string $returnUrl): string
    {
        if (! $cabinet->stripe_customer_id) {
            throw new RuntimeException('Aucun abonnement Stripe pour ce cabinet.');
        }

        return $this->stripe->billingPortal->sessions->create([
            'customer' => $cabinet->stripe_customer_id,
            'return_url' => $returnUrl,
            'locale' => 'fr-CA',
        ])->url;
    }

    public function updateSubscriptionSeats(Cabinet $cabinet, int $seats): void
    {
        if (! $cabinet->stripe_subscription_id) {
            return;
        }
        $subscription = $this->stripe->subscriptions->retrieve($cabinet->stripe_subscription_id);
        $item = $subscription->items->data[0] ?? null;
        if ($item && (int) $item->quantity !== $seats) {
            $this->stripe->subscriptionItems->update($item->id, [
                'quantity' => max(1, $seats),
                'proration_behavior' => 'create_prorations',
            ]);
        }
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        if (! $this->webhookSecret) {
            throw new RuntimeException('STRIPE_WEBHOOK_SECRET non configuré.');
        }
        try {
            $event = Webhook::constructEvent($payload, (string) $signature, $this->webhookSecret);
        } catch (\Throwable $e) {
            throw new RuntimeException('Signature de webhook invalide.', 0, $e);
        }

        return ['type' => $event->type, 'object' => $event->data->object->toArray()];
    }
}
