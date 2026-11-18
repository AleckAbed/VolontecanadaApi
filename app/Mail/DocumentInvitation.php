<?php

namespace App\Mail;

use App\Models\DocumentRequest;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class DocumentInvitation extends QueuedMail
{
    public function __construct(
        public DocumentRequest $documentRequest
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Document à remplir — ' . $this->documentRequest->template?->name,
        );
    }

    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');
        $fillUrl = $baseUrl . '/document/' . $this->documentRequest->token;
        $expiresAt = $this->documentRequest->expires_at?->format('d/m/Y');

        return new Content(
            view: 'emails.document-invitation',
            with: [
                'documentRequest' => $this->documentRequest,
                'fillUrl' => $fillUrl,
                'expiresAt' => $expiresAt,
                'clientName' => $this->documentRequest->client?->full_name ?? 'Cher client',
                'documentName' => $this->documentRequest->template?->name ?? 'Document',
                'personalMessage' => $this->documentRequest->message,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }

    /** Échec définitif après les 3 essais : le statut affiché au cabinet passe à « non envoyé ». */
    public function failed(\Throwable $e): void
    {
        $this->documentRequest->forceFill(['email_sent' => false, 'email_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
    }
}
