<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Facture envoyée au client, avec le lien pour la consulter et la payer. */
class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public ?Cabinet $cabinet, public bool $isReceipt = false) {}

    public function envelope(): Envelope
    {
        $cabinet = $this->cabinet?->name ?? 'Votre cabinet';

        return new Envelope(
            subject: $this->isReceipt
                ? "Reçu — facture {$this->invoice->number} — {$cabinet}"
                : "Facture {$this->invoice->number} — {$cabinet}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice',
            with: [
                'invoice' => $this->invoice,
                'cabinetName' => $this->cabinet?->name ?? 'Votre cabinet',
                'isReceipt' => $this->isReceipt,
                'url' => rtrim(config('app.frontend_url'), '/') . '/facture/' . $this->invoice->public_token,
                'money' => fn (int $cents) => number_format($cents / 100, 2, ',', ' ') . ' $',
            ],
        );
    }
}
