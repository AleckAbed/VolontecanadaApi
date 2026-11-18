<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Mandate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Mandat à signer (lien), ou copie du mandat signé. */
class MandateMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Mandate $mandate, public ?Cabinet $cabinet, public bool $signedCopy) {}

    public function envelope(): Envelope
    {
        $cabinet = $this->cabinet?->name ?? 'Votre cabinet';

        return new Envelope(
            subject: $this->signedCopy ? "Copie de votre mandat signé — {$cabinet}" : "Mandat à signer — {$cabinet}",
        );
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
