<?php

namespace App\Services\Payments;

use App\Models\Cabinet;
use App\Models\Invoice;
use RuntimeException;

/**
 * Passerelle factice pour les tests : enregistre les appels, ne contacte rien.
 * parseWebhook accepte un JSON { type, object } signé « valid ».
 */
class FakeGateway implements PaymentGateway
{
    /** @var array<int, array{method: string, args: array}> */
    public array $calls = [];

    public bool $chargesEnabled = true;

    public function isConfigured(): bool
    {
        return true;
    }

    public function createConnectedAccount(Cabinet $cabinet, string $email): string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$cabinet->id, $email]];

        return 'acct_fake_' . $cabinet->id;
    }

    public function connectOnboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$accountId]];

        return "https://connect.stripe.test/onboarding/{$accountId}";
    }

    public function connectedAccountStatus(string $accountId): array
    {
        return ['charges_enabled' => $this->chargesEnabled, 'details_submitted' => $this->chargesEnabled];
    }

    public function createInvoiceCheckout(Invoice $invoice, Cabinet $cabinet, string $successUrl, string $cancelUrl): array
    {
        if (! $cabinet->stripe_account_id || ! $cabinet->stripe_charges_enabled) {
            throw new RuntimeException('Le cabinet n\'a pas encore activé les paiements en ligne.');
        }
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$invoice->id, $invoice->total_cents, $cabinet->stripe_account_id]];

        return ['id' => "cs_fake_{$invoice->id}", 'url' => "https://checkout.stripe.test/cs_fake_{$invoice->id}"];
    }

    public function createSubscriptionCheckout(Cabinet $cabinet, int $seats, string $email, string $successUrl, string $cancelUrl): array
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$cabinet->id, $seats]];

        return ['id' => "cs_sub_{$cabinet->id}", 'url' => "https://checkout.stripe.test/cs_sub_{$cabinet->id}"];
    }

    public function billingPortalUrl(Cabinet $cabinet, string $returnUrl): string
    {
        return "https://billing.stripe.test/{$cabinet->stripe_customer_id}";
    }

    public function updateSubscriptionSeats(Cabinet $cabinet, int $seats): void
    {
        $this->calls[] = ['method' => __FUNCTION__, 'args' => [$cabinet->id, $seats]];
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        if ($signature !== 'valid') {
            throw new RuntimeException('Signature de webhook invalide.');
        }
        $data = json_decode($payload, true);

        return ['type' => $data['type'], 'object' => $data['object']];
    }

    public function called(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }
}
