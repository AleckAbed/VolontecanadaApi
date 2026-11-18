<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\Invoice;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Request;

/**
 * Facture vue par le client via son lien (/facture/{token}) : consultation,
 * impression, paiement par carte (Stripe Checkout, encaissé par le cabinet).
 * Le cabinet est résolu depuis le jeton (middleware cabinet.code:invoice).
 */
class PublicInvoiceController extends Controller
{
    public function show(string $token, PaymentGateway $gateway)
    {
        $invoice = Invoice::with(['lines', 'client'])->where('public_token', $token)->where('status', '!=', 'draft')->firstOrFail();
        $cabinet = Cabinet::find($invoice->cabinet_id);

        return response()->json(['success' => true, 'data' => InvoiceController::detail($invoice, $cabinet) + [
            'can_pay_online' => $invoice->isPayable() && $gateway->isConfigured() && $cabinet?->stripe_charges_enabled,
        ]]);
    }

    public function checkout(Request $request, string $token, PaymentGateway $gateway)
    {
        $invoice = Invoice::with('client')->where('public_token', $token)->firstOrFail();
        if (! $invoice->isPayable()) {
            return response()->json(['success' => false, 'message' => 'Cette facture n\'est pas payable en ligne (déjà payée ou annulée).'], 422);
        }

        $cabinet = Cabinet::find($invoice->cabinet_id);
        $base = rtrim(config('app.frontend_url'), '/') . "/facture/{$token}";

        try {
            $session = $gateway->createInvoiceCheckout($invoice, $cabinet, "{$base}?paiement=ok", "{$base}?paiement=annule");
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $invoice->forceFill(['stripe_checkout_session_id' => $session['id']])->save();

        return response()->json(['success' => true, 'data' => ['url' => $session['url']]]);
    }

    /** Portail client : ses factures émises. */
    public function forClient(Request $request)
    {
        $client = $request->user('client');
        $invoices = Invoice::where('client_id', $client->id)->where('status', '!=', 'draft')->orderByDesc('issued_at')->get();

        return response()->json(['success' => true, 'data' => $invoices->map(fn (Invoice $i) => InvoiceController::summary($i) + [
            'url' => '/facture/' . $i->public_token,
        ])->values()]);
    }
}
