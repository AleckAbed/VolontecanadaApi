<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Invoice;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Facture envoyée au client, avec le lien pour la consulter et la payer. */
class InvoiceMail extends QueuedMail
{
    public function __construct(public Invoice $invoice, public ?Cabinet $cabinet, public bool $isReceipt = false) {}

    public function envelope(): Envelope
    {
        $vars = ['number' => $this->invoice->number, 'cabinet' => $this->cabinet?->name ?? __('emails.immigration_firm')];

        return new Envelope(subject: __($this->isReceipt ? 'emails.invoice.receipt_subject' : 'emails.invoice.subject', $vars));
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
