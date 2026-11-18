<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Code de connexion à 6 chiffres (double authentification par courriel, 2.7). Envoyé sans file d'attente. */
class TwoFactorCodeMail extends QueuedMail
{
    public function __construct(public Admin $admin, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Votre code de connexion : {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.two-factor-code', with: ['name' => $this->admin->name, 'code' => $this->code]);
    }
}
