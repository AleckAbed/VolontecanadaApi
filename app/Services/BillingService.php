<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\Log;

/**
 * Abonnement des cabinets à la plateforme : sièges = comptes actifs du personnel.
 */
class BillingService
{
    public function __construct(private PaymentGateway $gateway) {}

    public function seatsUsed(Cabinet $cabinet): int
    {
        return Admin::acrossCabinets()->where('cabinet_id', $cabinet->id)->where('is_active', true)->count();
    }

    /** Met à jour la quantité de l'abonnement Stripe après un changement d'équipe. */
    public function syncSeats(Cabinet $cabinet): void
    {
        if (! $cabinet->stripe_subscription_id || ! in_array($cabinet->subscription_status, ['active', 'past_due'], true)) {
            return;
        }
        $seats = max(1, $this->seatsUsed($cabinet));
        if ($cabinet->subscribed_seats === $seats) {
            return;
        }
        try {
            $this->gateway->updateSubscriptionSeats($cabinet, $seats);
            $cabinet->forceFill(['subscribed_seats' => $seats])->save();
        } catch (\Throwable $e) {
            // Le changement d'équipe ne doit pas échouer à cause de Stripe : on journalise.
            Log::error('Synchronisation des sièges échouée', ['cabinet_id' => $cabinet->id, 'error' => $e->getMessage()]);
        }
    }

    /** Applique l'état d'un abonnement Stripe (webhook customer.subscription.*). */
    public function applySubscription(Cabinet $cabinet, array $subscription): void
    {
        $quantity = $subscription['items']['data'][0]['quantity'] ?? $subscription['quantity'] ?? null;

        $cabinet->forceFill([
            'stripe_subscription_id' => $subscription['id'] ?? $cabinet->stripe_subscription_id,
            'stripe_customer_id' => $subscription['customer'] ?? $cabinet->stripe_customer_id,
            'subscription_status' => match ($subscription['status'] ?? '') {
                'active' => 'active',
                'trialing' => 'trialing',
                'past_due' => 'past_due',
                'unpaid' => 'unpaid',
                'canceled', 'incomplete_expired' => 'canceled',
                default => $cabinet->subscription_status,
            },
            'current_period_end' => isset($subscription['current_period_end'])
                ? \Illuminate\Support\Carbon::createFromTimestamp($subscription['current_period_end'])
                : $cabinet->current_period_end,
            'subscribed_seats' => $quantity ?? $cabinet->subscribed_seats,
        ])->save();
    }
}
