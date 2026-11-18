<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\Payments\PaymentGateway;
use App\Support\Tenancy\CabinetRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Factures (admin) + présentation commune (admin, page publique, portail client).
 */
class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $query = Invoice::with('client:id,first_name,last_name')->orderByDesc('created_at');
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        $list = $query->limit(500)->get();

        return response()->json([
            'success' => true,
            'data' => [
                'invoices' => $list->map(fn (Invoice $i) => self::summary($i))->values(),
                'totals' => [
                    'outstanding_cents' => (int) $list->where('status', 'sent')->sum('total_cents'),
                    'paid_this_month_cents' => (int) $list->where('status', 'paid')
                        ->filter(fn ($i) => $i->paid_at && $i->paid_at->isSameMonth(now()))->sum('total_cents'),
                ],
            ],
        ]);
    }

    public function show(int $id, PaymentGateway $gateway)
    {
        $invoice = Invoice::with(['lines', 'client', 'dossier:id,name'])->findOrFail($id);
        $cabinet = Cabinet::find($invoice->cabinet_id);

        return response()->json(['success' => true, 'data' => self::detail($invoice, $cabinet) + [
            'online_payment_available' => $gateway->isConfigured() && $cabinet?->stripe_charges_enabled,
        ]]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $client = Client::findOrFail($data['client_id']);

        if ($error = $this->companyError($client, $data['company_id'] ?? null)) {
            return $error;
        }
        $invoice = $this->invoices->saveDraft(null, $client, $data['dossier_id'] ?? null, $data['lines'], $data['due_date'] ?? null, $data['notes'] ?? null, $request->user('admin')->id);
        $invoice->forceFill(['company_id' => $data['company_id'] ?? null])->save();

        return response()->json(['success' => true, 'message' => "Facture {$invoice->number} créée (brouillon).", 'data' => ['id' => $invoice->id]], 201);
    }

    public function update(Request $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);
        if ($invoice->status !== 'draft') {
            return response()->json(['success' => false, 'message' => 'Seul un brouillon peut être modifié. Annulez la facture et créez-en une nouvelle.'], 422);
        }
        $data = $this->validated($request);
        $client = Client::findOrFail($data['client_id']);
        if ($error = $this->companyError($client, $data['company_id'] ?? null)) {
            return $error;
        }
        $this->invoices->saveDraft($invoice, $client, $data['dossier_id'] ?? null, $data['lines'], $data['due_date'] ?? null, $data['notes'] ?? null, null);
        $invoice->forceFill(['company_id' => $data['company_id'] ?? null])->save();

        return response()->json(['success' => true, 'message' => 'Facture enregistrée.']);
    }

    public function send(int $id)
    {
        $invoice = Invoice::with('lines')->findOrFail($id);
        if (! in_array($invoice->status, ['draft', 'sent'], true)) {
            return response()->json(['success' => false, 'message' => 'Cette facture ne peut plus être envoyée.'], 422);
        }
        if ($invoice->lines->isEmpty() || $invoice->total_cents <= 0) {
            return response()->json(['success' => false, 'message' => 'Ajoutez au moins une ligne avec un montant.'], 422);
        }
        $sent = $this->invoices->send($invoice);

        return response()->json([
            'success' => true,
            'message' => $sent ? 'Facture envoyée au client par courriel.' : 'Facture émise, mais le courriel n\'a pas pu partir (vérifiez l\'adresse du client).',
            'email_sent' => $sent,
        ]);
    }

    public function markPaid(Request $request, int $id)
    {
        $invoice = Invoice::findOrFail($id);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_keys(Invoice::PAYMENT_METHODS))],
            'reference' => 'nullable|string|max:191',
        ]);
        if (! in_array($invoice->status, ['draft', 'sent'], true)) {
            return response()->json(['success' => false, 'message' => 'Cette facture est déjà payée ou annulée.'], 422);
        }
        $this->invoices->markPaid($invoice, $data['method'], $data['reference'] ?? null);

        return response()->json(['success' => true, 'message' => 'Paiement enregistré. Le reçu a été envoyé au client.']);
    }

    public function void(int $id)
    {
        $invoice = Invoice::findOrFail($id);
        if ($invoice->status === 'paid') {
            return response()->json(['success' => false, 'message' => 'Une facture payée ne peut pas être annulée ici (remboursement à faire dans Stripe).'], 422);
        }
        $this->invoices->void($invoice);

        return response()->json(['success' => true, 'message' => 'Facture annulée.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', CabinetRule::exists('clients')],
            'dossier_id' => ['nullable', CabinetRule::exists('dossiers')],
            // Facture adressée à l'entreprise de l'employé (le client reste l'employé concerné).
            'company_id' => ['nullable', CabinetRule::exists('companies')],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:9999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.taxable' => ['sometimes', 'boolean'],
        ]);
    }

    // ── Présentation ────────────────────────────────────────────────────────

    public static function summary(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'number' => $i->number,
            'status' => $i->status,
            'client' => $i->client ? ['id' => $i->client->id, 'name' => trim("{$i->client->first_name} {$i->client->last_name}")] : null,
            'total_cents' => $i->total_cents,
            'issued_at' => $i->issued_at?->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'paid_at' => $i->paid_at?->toDateString(),
            'is_overdue' => $i->status === 'sent' && $i->due_date && $i->due_date->isPast(),
        ];
    }

    public static function detail(Invoice $i, ?Cabinet $cabinet): array
    {
        return self::summary($i) + [
            'currency' => $i->currency,
            'tax_province' => $i->tax_province,
            'subtotal_cents' => $i->subtotal_cents,
            'tax_lines' => $i->tax_lines ?? [],
            'tax_cents' => $i->tax_cents,
            'notes' => $i->notes,
            'payment_method' => $i->payment_method,
            'payment_method_label' => Invoice::PAYMENT_METHODS[$i->payment_method] ?? null,
            'dossier' => $i->dossier ? ['id' => $i->dossier->id, 'name' => $i->dossier->name] : null,
            'client' => $i->client ? [
                'id' => $i->client->id,
                'name' => trim("{$i->client->first_name} {$i->client->last_name}"),
                'email' => $i->client->email,
                'address' => $i->client->address,
            ] : null,
            // Facture adressée à l'entreprise : c'est elle qui figure dans « Facturé à ».
            'company' => ($co = $i->company_id ? \App\Models\Company::find($i->company_id) : null) ? [
                'id' => $co->id,
                'name' => $co->name,
                'email' => $co->email,
                'address' => trim(implode("\n", array_filter([$co->address, trim(($co->city ?? '') . ' ' . ($co->province ?? '') . ' ' . ($co->postal_code ?? ''))]))),
                'business_number' => $co->business_number,
            ] : null,
            'lines' => $i->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => $l->quantity,
                'unit_price_cents' => $l->unit_price_cents,
                'taxable' => $l->taxable,
                'amount_cents' => $l->amount_cents,
            ])->values(),
            'cabinet' => $cabinet ? [
                'name' => $cabinet->name,
                'province' => $cabinet->province,
                'phone' => $cabinet->phone,
                'gst_number' => $cabinet->gst_number,
                'qst_number' => $cabinet->qst_number,
                'website' => $cabinet->website,
                'branding' => $cabinet->branding(),
            ] : null,
            'public_url' => rtrim(config('app.frontend_url'), '/') . '/facture/' . $i->public_token,
        ];
    }

    /** L'entreprise facturée doit être (ou avoir été) l'employeur du client. */
    private function companyError(Client $client, ?int $companyId)
    {
        if ($companyId && ! \Illuminate\Support\Facades\DB::table('company_client')->where('company_id', $companyId)->where('client_id', $client->id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Ce client n’est pas lié à cette entreprise.'], 422);
        }

        return null;
    }
}
