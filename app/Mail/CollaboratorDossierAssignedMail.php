<?php

namespace App\Mail;

use App\Models\Collaborator;
use App\Models\Dossier;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CollaboratorDossierAssignedMail extends QueuedMail
{
    public function __construct(public Collaborator $collaborator, public Dossier $dossier) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouveau dossier qui vous est assigné — '
                . (\App\Models\Cabinet::find($this->collaborator->cabinet_id)?->name ?? config('app.name')),
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');
        $dossierUrl = $baseUrl . '/collab/dossiers/' . $this->dossier->id;

        $clientName = $this->dossier->client
            ? trim(($this->dossier->client->first_name ?? '') . ' ' . ($this->dossier->client->last_name ?? ''))
            : '—';

        return new Content(
            view: 'emails.collaborator-dossier-assigned',
            with: [
                'collaborator' => $this->collaborator,
                'dossier' => $this->dossier,
                'clientName' => $clientName,
                'dossierUrl' => $dossierUrl,
            ],
        );
    }
}
