<?php

namespace App\Mail;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\AdminAccessTokens;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AdminPasswordResetMail extends QueuedMail
{
    public function __construct(
        public Admin $admin,
        public ?Cabinet $cabinet,
        public string $token,
        public string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === AdminAccessTokens::INVITE
                ? 'Activez votre compte — ' . ($this->cabinet?->name ?? 'votre cabinet')
                : 'Réinitialisation de votre mot de passe',
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.admin-password-reset',
            with: [
                'admin' => $this->admin,
                'cabinetName' => $this->cabinet?->name ?? 'votre cabinet',
                'isInvite' => $this->purpose === AdminAccessTokens::INVITE,
                'actionUrl' => $baseUrl . '/auth/definir-mot-de-passe?token=' . urlencode($this->token),
            ],
        );
    }
}
