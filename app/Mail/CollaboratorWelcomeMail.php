<?php

namespace App\Mail;

use App\Models\Collaborator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CollaboratorWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Collaborator $collaborator, public string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Activez votre accès à l\'espace collaborateur — Volonté Canada',
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');
        $activationUrl = $baseUrl . '/collab/activate?token=' . urlencode($this->token);

        return new Content(
            view: 'emails.collaborator-welcome',
            with: [
                'collaborator' => $this->collaborator,
                'activationUrl' => $activationUrl,
            ],
        );
    }
}
