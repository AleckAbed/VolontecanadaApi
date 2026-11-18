<?php

namespace App\Mail;

use App\Models\QuestionnaireRequest;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class QuestionnaireInvitation extends QueuedMail
{
    /**
     * Create a new message instance.
     */
    public function __construct(
        public QuestionnaireRequest $questionnaireRequest
    ) {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à compléter votre formulaire d\'immigration',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $baseUrl = rtrim(config('app.frontend_url'), '/');
        $verificationUrl = $baseUrl . '/questionnaire/verify?email=' . urlencode($this->questionnaireRequest->email) . '&code=' . $this->questionnaireRequest->unique_code;
        $expiresAt = $this->questionnaireRequest->expires_at->format('d/m/Y');
        
        return new Content(
            view: 'emails.questionnaire-invitation',
            with: [
                'questionnaireRequest' => $this->questionnaireRequest,
                'verificationUrl' => $verificationUrl,
                'uniqueCode' => $this->questionnaireRequest->unique_code,
                'expiresAt' => $expiresAt,
                'clientName' => $this->questionnaireRequest->custom_name ?? 
                    ($this->questionnaireRequest->client ? 
                        $this->questionnaireRequest->client->first_name . ' ' . $this->questionnaireRequest->client->last_name : 
                        'Cher client'),
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    /** Échec définitif après les 3 essais : le statut affiché au cabinet passe à « non envoyé ». */
    public function failed(\Throwable $e): void
    {
        $this->questionnaireRequest->forceFill(['email_sent' => false, 'email_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
    }
}
