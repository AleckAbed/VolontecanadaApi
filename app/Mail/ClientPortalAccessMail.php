<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Client;
use App\Support\AdminAccessTokens;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClientPortalAccessMail extends QueuedMail
{
    /** Client déjà inscrit : simple avis « du nouveau vous attend », sans lien d'accès. */
    public const REMINDER = 'reminder';

    public function __construct(
        public Client $client,
        public ?Cabinet $cabinet,
        public string $token,
        public string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.portal.' . $this->mode() . '_subject', ['cabinet' => $this->cabinetName()]));
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');
        $mode = $this->mode();

        return new Content(
            view: 'emails.client-portal-access',
            with: [
                'client' => $this->client,
                'cabinetName' => $this->cabinetName(),
                'mode' => $mode,
                // Rappel : le client a déjà son mot de passe, on l'envoie simplement se connecter.
                'actionUrl' => $mode === 'reminder'
                    ? $baseUrl . '/auth/client-signin'
                    : $baseUrl . '/client/acces?token=' . urlencode($this->token),
                'loginUrl' => $baseUrl . '/auth/client-signin',
            ],
        );
    }

    /** « invite » (pas encore de compte), « reminder » (compte actif : du nouveau l'attend), « reset ». */
    private function mode(): string
    {
        return match ($this->purpose) {
            AdminAccessTokens::INVITE => 'invite',
            self::REMINDER => 'reminder',
            default => 'reset',
        };
    }

    private function cabinetName(): string
    {
        return $this->cabinet?->name ?? __('emails.immigration_firm');
    }
}
