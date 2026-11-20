<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvitationMail extends QueuedMail
{
    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('emails.envoi.subject', ['cabinet' => \App\Models\Cabinet::find($this->invitation->cabinet_id)?->name ?? __('emails.immigration_firm')]),
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

    /** Échec définitif après les 3 essais : le statut affiché au cabinet passe à « non envoyé ». */
    public function failed(\Throwable $e): void
    {
        $this->invitation->forceFill(['email_sent' => false, 'email_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
    }
}
