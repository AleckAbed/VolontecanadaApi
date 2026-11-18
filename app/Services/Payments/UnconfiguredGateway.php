<?php

namespace App\Services\Payments;

use App\Models\Cabinet;
use App\Models\Invoice;
use RuntimeException;

/** Aucune clé Stripe : les fonctions de paiement en ligne sont indisponibles. */
class UnconfiguredGateway implements PaymentGateway
{
    private const MESSAGE = 'Les paiements en ligne ne sont pas encore configurés sur la plateforme (clés Stripe manquantes).';

    public function isConfigured(): bool
    {
        return false;
    }

    public function createConnectedAccount(Cabinet $cabinet, string $email): string
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function connectOnboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function connectedAccountStatus(string $accountId): array
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function createInvoiceCheckout(Invoice $invoice, Cabinet $cabinet, string $successUrl, string $cancelUrl): array
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function createSubscriptionCheckout(Cabinet $cabinet, int $seats, string $email, string $successUrl, string $cancelUrl): array
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function billingPortalUrl(Cabinet $cabinet, string $returnUrl): string
    {
        throw new RuntimeException(self::MESSAGE);
    }

    public function updateSubscriptionSeats(Cabinet $cabinet, int $seats): void
    {
        // Rien à synchroniser sans Stripe.
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
