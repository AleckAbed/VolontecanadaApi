<?php

namespace App\Mail;

use App\Models\DocumentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentInvitation extends Mailable
{
    use Queueable, SerializesModels;

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
}
