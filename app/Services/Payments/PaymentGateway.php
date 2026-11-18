<?php

namespace App\Services\Payments;

use App\Models\Cabinet;
use App\Models\Invoice;

/**
 * Passerelle de paiement de la plateforme.
 * Implémentations : StripeGateway (production), UnconfiguredGateway (pas de
 * clé Stripe), FakeGateway (tests).
 */
interface PaymentGateway
{
    public function isConfigured(): bool;

    // ── Stripe Connect : le cabinet encaisse ses clients ───────────────────

    /** Crée le compte connecté du cabinet et renvoie son identifiant. */
    public function createConnectedAccount(Cabinet $cabinet, string $email): string;

    /** Lien d'inscription / de mise à jour du compte connecté. */
    public function connectOnboardingUrl(string $accountId, string $refreshUrl, string $returnUrl): string;

    /** @return array{charges_enabled: bool, details_submitted: bool} */
    public function connectedAccountStatus(string $accountId): array;

    /** @return array{id: string, url: string} */
    public function createInvoiceCheckout(Invoice $invoice, Cabinet $cabinet, string $successUrl, string $cancelUrl): array;

    // ── Abonnement du cabinet à la plateforme ──────────────────────────────

    /** @return array{id: string, url: string} */
    public function createSubscriptionCheckout(Cabinet $cabinet, int $seats, string $email, string $successUrl, string $cancelUrl): array;

    public function billingPortalUrl(Cabinet $cabinet, string $returnUrl): string;

    public function updateSubscriptionSeats(Cabinet $cabinet, int $seats): void;

    // ── Webhooks ───────────────────────────────────────────────────────────

    /**
     * Vérifie la signature et renvoie l'événement.
     *
     * @return array{type: string, object: array}
     *
     * @throws \RuntimeException signature invalide
     */
    public function parseWebhook(string $payload, ?string $signature): array;
}
