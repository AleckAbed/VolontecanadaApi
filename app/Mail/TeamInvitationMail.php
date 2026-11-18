<?php

namespace App\Mail;

use App\Models\Admin;
use App\Models\Cabinet;
use App\Support\Roles;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TeamInvitationMail extends QueuedMail
{
    public function __construct(
        public Admin $member,
        public ?Cabinet $cabinet,
        public Admin $invitedBy,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rejoignez l\'équipe de ' . ($this->cabinet?->name ?? 'votre cabinet'),
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');

        return new Content(
            view: 'emails.team-invitation',
            with: [
                'member' => $this->member,
                'cabinetName' => $this->cabinet?->name ?? 'votre cabinet',
                'invitedByName' => $this->invitedBy->name,
                'roleLabel' => Roles::label($this->member->role),
                'actionUrl' => $baseUrl . '/auth/definir-mot-de-passe?token=' . urlencode($this->token),
                'loginUrl' => $baseUrl . '/auth/admin-signin',
            ],
        );
    }
}
