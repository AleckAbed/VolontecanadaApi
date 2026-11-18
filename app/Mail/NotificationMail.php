<?php

namespace App\Mail;

use App\Models\Admin;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Une notification envoyée par courriel, selon les préférences de la personne. */
class NotificationMail extends QueuedMail
{
    public function __construct(public Admin $admin, public string $title, public ?string $body, public ?string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        return new Content(view: 'emails.notification', with: [
            'name' => $this->admin->name,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->link ? $base . $this->link : $base,
            'preferencesUrl' => $base . '/admin/notifications',
        ]);
    }
}
