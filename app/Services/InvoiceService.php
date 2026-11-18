<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Mail\InvoicePaidMail;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\CanadianTaxes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Factures clients : numérotation par cabinet, calcul des taxes, envoi,
 * encaissement (en ligne via Stripe ou noté à la main), reçu.
 */
class InvoiceService
{
    public function __construct(private ClientProfileService $profiles, private NotificationService $notifications) {}

    /**
     * Crée ou remplace le contenu d'une facture brouillon.
     *
     * @param  array<int, array{description: string, quantity: float|int|string, unit_price: float|int|string, taxable?: bool}>  $lines
     */
    public function saveDraft(?Invoice $invoice, Client $client, ?int $dossierId, array $lines, ?string $dueDate, ?string $notes, ?int $adminId): Invoice
    {
        return DB::transaction(function () use ($invoice, $client, $dossierId, $lines, $dueDate, $notes, $adminId) {
            $cabinet = Cabinet::lockForUpdate()->findOrFail($client->cabinet_id);

            if (! $invoice) {
                $invoice = new Invoice([
                    'number' => $this->nextNumber($cabinet),
                    'status' => 'draft',
                    'public_token' => Str::random(48),
                    'created_by' => $adminId,
                ]);
            }

            $invoice->fill([
                'client_id' => $client->id,
                'dossier_id' => $dossierId,
                'due_date' => $dueDate,
                'notes' => $notes,
                'tax_province' => $this->taxProvince($client, $cabinet),
            ]);
            $invoice->save();

            $invoice->lines()->delete();
            foreach (array_values($lines) as $i => $line) {
                $qty = round((float) $line['quantity'], 2);
                $unit = (int) round(((float) $line['unit_price']) * 100);
                $invoice->lines()->create([
                    'description' => $line['description'],
                    'quantity' => $qty,
                    'unit_price_cents' => $unit,
                    'taxable' => (bool) ($line['taxable'] ?? true),
                    'amount_cents' => (int) round($qty * $unit),
                    'sort_order' => $i,
                ]);
            }

            return $this->recompute($invoice->fresh('lines'));
        });
    }

    public function recompute(Invoice $invoice): Invoice
    {
        $subtotal = (int) $invoice->lines->sum('amount_cents');
        $taxable = (int) $invoice->lines->where('taxable', true)->sum('amount_cents');
        $taxLines = CanadianTaxes::compute($taxable, $invoice->tax_province);
        $tax = array_sum(array_column($taxLines, 'amount_cents'));

        $invoice->forceFill([
            'subtotal_cents' => $subtotal,
            'tax_lines' => $taxLines,
            'tax_cents' => $tax,
            'total_cents' => $subtotal + $tax,
        ])->save();

        return $invoice;
    }

    /** Émet la facture et l'envoie au client par courriel (lien de paiement). */
    public function send(Invoice $invoice): bool
    {
        if ($invoice->status === 'draft') {
            $invoice->forceFill(['status' => 'sent', 'issued_at' => now()])->save();
        }

        $client = $invoice->client;
        if (! $client?->email) {
            return false;
        }
        try {
            Mail::to($client->email)->send(new InvoiceMail($invoice->fresh(['lines', 'client']), Cabinet::find($invoice->cabinet_id)));

            return true;
        } catch (\Throwable $e) {
            Log::error('Envoi de facture échoué', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Encaissement (idempotent) : paiement en ligne confirmé par Stripe ou
     * paiement noté par le cabinet. Envoie le reçu et prévient le cabinet.
     */
    public function markPaid(Invoice $invoice, string $method, ?string $reference = null, ?string $paymentIntentId = null): Invoice
    {
        if ($invoice->status === 'paid') {
            return $invoice;
        }

        $invoice->forceFill([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $method,
            'payment_reference' => $reference,
            'stripe_payment_intent_id' => $paymentIntentId ?? $invoice->stripe_payment_intent_id,
            'issued_at' => $invoice->issued_at ?? now(),
        ])->save();

        // Facture d'une réservation payante : le rendez-vous en attente est confirmé.
        app(\App\Services\Booking\AppointmentScheduler::class)->confirmByInvoice($invoice);

        $cabinet = Cabinet::find($invoice->cabinet_id);
        $client = $invoice->client;
        if ($client?->email) {
            try {
                Mail::to($client->email)->send(new InvoicePaidMail($invoice->fresh(['lines', 'client']), $cabinet));
            } catch (\Throwable $e) {
                Log::error('Envoi du reçu échoué', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        if ($method === 'card') {
            $this->notifications->notify(
                $this->notifications->recipientsFor($invoice->created_by),
                'invoice_paid',
                "Facture {$invoice->number} payée en ligne",
                trim(($client?->first_name ?? '') . ' ' . ($client?->last_name ?? '')) . ' — ' . number_format($invoice->total_cents / 100, 2, ',', ' ') . ' $',
                "/facturation/{$invoice->id}",
                "invoice_paid:{$invoice->id}",
            );
        }

        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        $invoice->forceFill(['status' => 'void'])->save();

        return $invoice;
    }

    /** Province de taxation : celle du client si connue, sinon celle du cabinet. */
    private function taxProvince(Client $client, Cabinet $cabinet): ?string
    {
        $profile = $this->profiles->resolve($client);
        $fromClient = ($profile['country'] ?? 'Canada') !== '' && ! preg_match('/canada/i', $profile['country'] ?? 'Canada')
            ? null // client hors Canada : on retient la province du cabinet
            : CanadianTaxes::normalizeProvince($profile['province'] ?? null);

        return $fromClient ?? CanadianTaxes::normalizeProvince($cabinet->province) ?? 'QC';
    }

    /** Numéro séquentiel par cabinet et par année : 2026-0001. */
    private function nextNumber(Cabinet $cabinet): string
    {
        $n = $cabinet->next_invoice_number ?: 1;
        $cabinet->forceFill(['next_invoice_number' => $n + 1])->save();

        return now()->format('Y') . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
