<?php

namespace App\Services;

use App\Mail\MandateMail;
use App\Models\Appointment;
use App\Models\Cabinet;
use App\Models\Client;
use App\Models\Dossier;
use App\Models\Mandate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Mandat de représentation à signer en ligne.
 *
 * Signature électronique simple : le client lit le texte figé, tape son nom
 * et coche son consentement. On conserve la date, l'adresse IP, le navigateur
 * et l'empreinte SHA-256 du texte signé.
 */
class MandateService
{
    public const DEFAULT_TITLE = 'Mandat de représentation';

    /** Texte par défaut, à adapter par chaque cabinet (Paramètres de réservation). */
    public const DEFAULT_TEXT = <<<'TXT'
Je soussigné(e), {{client_nom}}, mandate {{cabinet_nom}} pour me représenter et m'accompagner dans mes démarches d'immigration auprès des autorités canadiennes et québécoises{{dossier}}.

Ce mandat autorise le cabinet à :
- préparer, vérifier et transmettre en mon nom les formulaires et documents nécessaires à ma demande ;
- communiquer avec les autorités compétentes au sujet de mon dossier et recevoir la correspondance qui s'y rapporte ;
- me conseiller sur les options d'immigration adaptées à ma situation.

Je m'engage à fournir des renseignements exacts et complets, et à informer le cabinet de tout changement de situation.

Les honoraires, les modalités de paiement et les conditions de fin du mandat sont précisés dans l'entente de services conclue avec le cabinet. Je peux révoquer ce mandat à tout moment par écrit.

Les formulaires officiels de désignation d'un représentant exigés par les autorités (par exemple IMM 5476) restent à signer séparément.

Fait le {{date}}.
TXT;

    public function render(Cabinet $cabinet, Client $client, ?Dossier $dossier): string
    {
        $text = trim((string) $cabinet->mandate_text) !== '' ? $cabinet->mandate_text : self::DEFAULT_TEXT;

        return strtr($text, [
            '{{client_nom}}' => trim("{$client->first_name} {$client->last_name}"),
            '{{cabinet_nom}}' => $cabinet->name,
            '{{dossier}}' => $dossier ? " (dossier : {$dossier->name})" : '',
            '{{date}}' => now()->setTimezone($cabinet->timezone ?: 'America/Toronto')->locale('fr_CA')->isoFormat('D MMMM YYYY'),
        ]);
    }

    public function send(Client $client, ?Dossier $dossier, ?Appointment $appointment, ?int $adminId, ?string $title = null): Mandate
    {
        $cabinet = Cabinet::findOrFail($client->cabinet_id);
        $body = $this->render($cabinet, $client, $dossier);

        $mandate = Mandate::create([
            'client_id' => $client->id,
            'dossier_id' => $dossier?->id,
            'appointment_id' => $appointment?->id,
            'title' => $title ?: self::DEFAULT_TITLE,
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'status' => 'sent',
            'public_token' => Str::random(48),
            'sent_at' => now(),
            'created_by' => $adminId,
        ]);

        $this->mail($mandate, false);

        return $mandate;
    }

    /** Signature par le client. Le texte doit être celui envoyé (empreinte vérifiée). */
    public function sign(Mandate $mandate, string $signedName, ?string $ip, ?string $userAgent): Mandate
    {
        if (! hash_equals($mandate->body_hash, hash('sha256', $mandate->body))) {
            throw new \RuntimeException('Le texte du mandat a été modifié depuis son envoi : signature refusée.');
        }

        $mandate->forceFill([
            'status' => 'signed',
            'signed_name' => trim($signedName),
            'signed_at' => now(),
            'signed_ip' => $ip,
            'signed_user_agent' => Str::limit((string) $userAgent, 250, ''),
        ])->save();

        $this->mail($mandate, true);
        app(NotificationService::class)->notify(
            app(NotificationService::class)->recipientsFor($mandate->created_by),
            'mandate_signed',
            "Mandat signé : {$mandate->signed_name}",
            $mandate->title,
            "/admin/clients/{$mandate->client_id}",
            "mandate_signed:{$mandate->id}",
        );

        return $mandate;
    }

    private function mail(Mandate $mandate, bool $signedCopy): void
    {
        $client = Client::find($mandate->client_id);
        if (! $client?->email) {
            return;
        }
        try {
            Mail::to($client->email)->send(new MandateMail($mandate, Cabinet::find($mandate->cabinet_id), $signedCopy));
        } catch (\Throwable $e) {
            Log::error('Courriel de mandat non envoyé', ['mandate_id' => $mandate->id, 'error' => $e->getMessage()]);
        }
    }
}
