<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\CompanyContact;
use App\Support\AdminAccessTokens;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Lien d'accès à l'espace entreprise : invitation d'un contact, ou mot de passe oublié. */
class CompanyAccessMail extends QueuedMail
{
    public function __construct(public CompanyContact $contact, public ?Cabinet $cabinet, public string $token, public string $purpose) {}

    public function envelope(): Envelope
    {
        $cabinet = $this->cabinet?->name ?? 'Votre cabinet';

        return new Envelope(subject: $this->purpose === AdminAccessTokens::INVITE
            ? "Votre espace entreprise — {$cabinet}"
            : "Réinitialisation de votre mot de passe — {$cabinet}");
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return new Content(view: 'emails.company-access', with: [
            'name' => $this->contact->first_name,
            'companyName' => $this->contact->company?->name,
            'isInvite' => $this->purpose === AdminAccessTokens::INVITE,
            'actionUrl' => "{$base}/entreprise/acces?token={$this->token}",
            'loginUrl' => "{$base}/entreprise/connexion",
        ]);
    }
}
