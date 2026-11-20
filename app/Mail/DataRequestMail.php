<?php

namespace App\Mail;

use App\Models\DataRequest;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Réponse à une demande de suppression de renseignements (3.10). */
class DataRequestMail extends QueuedMail
{
    public function __construct(public DataRequest $dataRequest, public string $cabinetName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('emails.data_request.subject', ['cabinet' => $this->cabinetName]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.data-request', with: [
            'name' => $this->dataRequest->client_name,
            'cabinetName' => $this->cabinetName,
            'completed' => $this->dataRequest->status === 'completed',
            'response' => $this->dataRequest->response,
        ]);
    }
}
