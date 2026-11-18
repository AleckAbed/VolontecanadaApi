<?php

namespace App\Mail;

use App\Models\Cabinet;
use App\Models\Client;
use App\Support\AdminAccessTokens;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClientPortalAccessMail extends QueuedMail
{
    public function __construct(
        public Client $client,
        public ?Cabinet $cabinet,
        public string $token,
        public string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        $cabinetName = $this->cabinet?->name ?? 'votre cabinet';

        return new Envelope(
            subject: $this->purpose === AdminAccessTokens::INVITE
                ? "Votre portail client — {$cabinetName}"
                : "Réinitialisation de votre mot de passe — {$cabinetName}",
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.client-portal-access',
            with: [
                'client' => $this->client,
                'cabinetName' => $this->cabinet?->name ?? 'votre cabinet',
                'isInvite' => $this->purpose === AdminAccessTokens::INVITE,
                'actionUrl' => $baseUrl . '/client/acces?token=' . urlencode($this->token),
                'loginUrl' => $baseUrl . '/auth/client-signin',
            ],
        );
    }
}
