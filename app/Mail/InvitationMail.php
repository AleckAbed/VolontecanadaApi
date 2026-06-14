<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à compléter vos formulaires et documents',
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');
        // URL sans paramètres — le code n'est PAS dans le lien pour des raisons
        // de sécurité (un email transféré ou intercepté ne donne pas l'accès direct).
        // Le destinataire doit saisir son email + le code reçu dans l'email.
        $url = $baseUrl . '/invitation/verify';
        $expiresAt = $this->invitation->expires_at?->format('d/m/Y') ?? '—';

        $client = $this->invitation->custom_name
            ?: ($this->invitation->client
                ? $this->invitation->client->first_name . ' ' . $this->invitation->client->last_name
                : 'Cher client');

        $items = $this->invitation->items()->with(['formType', 'documentTemplate'])->get();

        return new Content(
            view: 'emails.invitation',
            with: [
                'invitation' => $this->invitation,
                'invitationUrl' => $url,
                'expiresAt' => $expiresAt,
                'clientName' => $client,
                'items' => $items,
                'customMessage' => $this->invitation->message,
            ],
        );
    }
}
