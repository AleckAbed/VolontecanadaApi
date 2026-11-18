<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Récapitulatif quotidien des nouvelles échéances à surveiller. */
class DeadlineDigestMail extends QueuedMail
{
    /** @param array<int, array{title: string, body: ?string, link: ?string, overdue: bool}> $items */
    public function __construct(public Admin $admin, public string $cabinetName, public array $items) {}

    public function envelope(): Envelope
    {
        $overdue = collect($this->items)->where('overdue', true)->count();

        return new Envelope(
            subject: $overdue > 0
                ? "{$overdue} échéance(s) en retard — {$this->cabinetName}"
                : count($this->items) . " échéance(s) à surveiller — {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.deadline-digest',
            with: [
                'admin' => $this->admin,
                'cabinetName' => $this->cabinetName,
                'items' => $this->items,
                'baseUrl' => rtrim(config('app.frontend_url'), '/'),
            ],
        );
    }
}
