<?php

namespace App\Mail;

use App\Models\CabinetRequest;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Courriels du parcours « demande d'ouverture de cabinet » :
 *  - received : accusé de réception au demandeur ;
 *  - new      : avis à l'équipe de la plateforme ;
 *  - approved : cabinet créé, lien pour activer le compte du propriétaire ;
 *  - declined : réponse négative, avec le motif s'il y en a un.
 */
class CabinetRequestMail extends QueuedMail
{
    public const RECEIVED = 'received';

    public const NEW = 'new';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    public function __construct(public CabinetRequest $request, public string $kind, public ?string $token = null) {}

    public function envelope(): Envelope
    {
        $app = config('app.name');

        return new Envelope(subject: match ($this->kind) {
            self::RECEIVED => "Votre demande d'ouverture de cabinet est bien reçue",
            self::NEW => "Nouvelle demande d'ouverture : {$this->request->cabinet_name}",
            self::APPROVED => "Votre cabinet est prêt sur {$app}",
            default => "Votre demande d'ouverture de cabinet",
        });
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return new Content(view: 'emails.cabinet-request', with: [
            'kind' => $this->kind,
            'req' => $this->request,
            'appName' => config('app.name'),
            'actionUrl' => match ($this->kind) {
                self::APPROVED => $base . '/auth/definir-mot-de-passe?token=' . urlencode((string) $this->token),
                self::NEW => $base . '/plateforme/demandes',
                default => null,
            },
        ]);
    }
}
