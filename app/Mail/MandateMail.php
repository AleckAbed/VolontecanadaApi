<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Mandate;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Mandat à signer (lien), ou copie du mandat signé. */
class MandateMail extends QueuedMail
{
    public function __construct(public Mandate $mandate, public ?Cabinet $cabinet, public bool $signedCopy) {}

    public function envelope(): Envelope
    {
        $cabinet = $this->cabinet?->name ?? __('emails.immigration_firm');

        return new Envelope(subject: __($this->signedCopy ? 'emails.mandate.copy_subject' : 'emails.mandate.subject', ['cabinet' => $cabinet]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mandate',
            with: [
                'mandate' => $this->mandate,
                'clientName' => trim(($this->mandate->client?->first_name ?? '') . ' ' . ($this->mandate->client?->last_name ?? '')),
                'cabinetName' => $this->cabinet?->name ?? 'Votre cabinet',
                'signedCopy' => $this->signedCopy,
                'url' => rtrim(config('app.frontend_url'), '/') . '/mandat/' . $this->mandate->public_token,
            ],
        );
    }
}
