<?php

namespace App\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Code de connexion à 6 chiffres (double authentification par courriel, 2.7). Envoyé sans file d'attente. */
class TwoFactorCodeMail extends QueuedMail
{
    /** @param Model $person Admin ou Collaborator */
    public function __construct(public Model $person, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Votre code de connexion : {$this->code}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.two-factor-code', with: ['name' => $this->person->getAttribute('name') ?: trim($this->person->getAttribute('first_name') . ' ' . $this->person->getAttribute('last_name')), 'code' => $this->code]);
    }
}
