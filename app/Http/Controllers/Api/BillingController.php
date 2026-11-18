<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Services\BillingService;
use App\Services\Payments\PaymentGateway;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;

/**
 * Paramètres de paiement du cabinet (propriétaires et administrateurs) :
 *  - abonnement à la plateforme (par siège) ;
 *  - compte Stripe Connect pour encaisser les clients ;
 *  - numéros de taxes affichés sur les factures.
 */
class BillingController extends Controller
{
    public function __construct(private PaymentGateway $gateway, private BillingService $billing) {}

    public function show()
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());

        return response()->json(['success' => true, 'data' => [
            'stripe_configured' => $this->gateway->isConfigured(),
            'subscription' => [
                'status' => $cabinet->subscription_status,
                'is_active' => $cabinet->hasActiveSubscription(),
                'trial_ends_at' => $cabinet->trial_ends_at?->toDateString(),
                'trial_days_left' => $cabinet->trialDaysLeft(),
                'current_period_end' => $cabinet->current_period_end?->toDateString(),
                'seats_used' => $this->billing->seatsUsed($cabinet),
                'subscribed_seats' => $cabinet->subscribed_seats,
                'has_customer' => (bool) $cabinet->stripe_customer_id,
            ],
            'payments' => [
                'connected' => (bool) $cabinet->stripe_account_id,
                'charges_enabled' => (bool) $cabinet->stripe_charges_enabled,
            ],
            'taxes' => [
                'province' => $cabinet->province,
                'gst_number' => $cabinet->gst_number,
                'qst_number' => $cabinet->qst_number,
            ],
        ]]);
    }

    public function subscribe(Request $request)
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $base = rtrim(config('app.frontend_url'), '/') . '/admin/abonnement';

        try {
            $session = $this->gateway->createSubscriptionCheckout(
                $cabinet,
                max(1, $this->billing->seatsUsed($cabinet)),
                $request->user('admin')->email,
                "{$base}?abonnement=ok",
                "{$base}?abonnement=annule",
            );
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => ['url' => $session['url']]]);
    }

    public function portal()
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        try {
            $url = $this->gateway->billingPortalUrl($cabinet, rtrim(config('app.frontend_url'), '/') . '/admin/abonnement');
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    }

    /** Démarre (ou reprend) l'inscription du cabinet à Stripe Connect. */
    public function connect(Request $request)
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $base = rtrim(config('app.frontend_url'), '/') . '/admin/abonnement';

        try {
            if (! $cabinet->stripe_account_id) {
                $cabinet->forceFill(['stripe_account_id' => $this->gateway->createConnectedAccount($cabinet, $request->user('admin')->email)])->save();
            }
            $url = $this->gateway->connectOnboardingUrl($cabinet->stripe_account_id, "{$base}?connect=relancer", "{$base}?connect=retour");
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => ['url' => $url]]);
    }

    /** Relit l'état du compte connecté (au retour de Stripe). */
    public function refreshConnect()
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        if (! $cabinet->stripe_account_id) {
            return response()->json(['success' => true, 'data' => ['charges_enabled' => false]]);
        }
        try {
            $status = $this->gateway->connectedAccountStatus($cabinet->stripe_account_id);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        $cabinet->forceFill(['stripe_charges_enabled' => $status['charges_enabled']])->save();

        return response()->json(['success' => true, 'data' => $status]);
    }

    public function updateTaxes(Request $request)
    {
        $cabinet = Cabinet::findOrFail(CurrentCabinet::require());
        $data = $request->validate([
            'province' => 'nullable|string|in:' . implode(',', array_keys(\App\Support\CanadianTaxes::RATES)),
            'gst_number' => 'nullable|string|max:30',
            'qst_number' => 'nullable|string|max:30',
        ]);
        $cabinet->update($data);

        return response()->json(['success' => true, 'message' => 'Informations de taxes enregistrées.']);
    }
}
