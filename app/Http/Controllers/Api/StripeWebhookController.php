<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\Invoice;
use App\Services\BillingService;
use App\Services\InvoiceService;
use App\Services\Payments\PaymentGateway;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhooks Stripe (compte plateforme et comptes connectés).
 * Seule source de vérité pour « facture payée » et l'état des abonnements :
 * on ne se fie jamais au retour du navigateur.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private PaymentGateway $gateway,
        private InvoiceService $invoices,
        private BillingService $billing,
    ) {}

    public function handle(Request $request)
    {
        try {
            $event = $this->gateway->parseWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (\RuntimeException $e) {
            Log::warning('Webhook Stripe refusé', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Signature invalide.'], 400);
        }

        $object = $event['object'];
        $cabinetId = (int) ($object['metadata']['cabinet_id'] ?? 0);

        switch ($event['type']) {
            case 'checkout.session.completed':
                $kind = $object['metadata']['kind'] ?? null;
                if ($kind === 'invoice' && $cabinetId && ($object['payment_status'] ?? '') === 'paid') {
                    CurrentCabinet::runAs($cabinetId, function () use ($object) {
                        $invoice = Invoice::find((int) ($object['metadata']['invoice_id'] ?? 0));
                        if ($invoice) {
                            $this->invoices->markPaid($invoice, 'card', null, $object['payment_intent'] ?? null);
                        }
                    });
                } elseif ($kind === 'subscription' && $cabinetId && ($cabinet = Cabinet::find($cabinetId))) {
                    $cabinet->forceFill([
                        'stripe_customer_id' => $object['customer'] ?? $cabinet->stripe_customer_id,
                        'stripe_subscription_id' => $object['subscription'] ?? $cabinet->stripe_subscription_id,
                        'subscription_status' => 'active',
                    ])->save();
                }
                break;

            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                $cabinet = $cabinetId
                    ? Cabinet::find($cabinetId)
                    : Cabinet::where('stripe_subscription_id', $object['id'] ?? '')->first();
                if ($cabinet) {
                    if ($event['type'] === 'customer.subscription.deleted') {
                        $object['status'] = 'canceled';
                    }
                    $this->billing->applySubscription($cabinet, $object);
                }
                break;

            case 'account.updated':
                Cabinet::where('stripe_account_id', $object['id'] ?? '')
                    ->update(['stripe_charges_enabled' => (bool) ($object['charges_enabled'] ?? false)]);
                break;
        }

        return response()->json(['received' => true]);
    }
}
