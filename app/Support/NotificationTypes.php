<?php

namespace App\Support;

use App\Models\Admin;

/**
 * Catalogue des notifications du personnel et préférences de chacun.
 * « app » : dans la cloche de l'en-tête ; « email » : un courriel par notification.
 * Le récapitulatif quotidien des échéances est un courriel à part (deadline_digest).
 */
final class NotificationTypes
{
    /** type => [libellé, groupe, dans l'application par défaut, courriel par défaut] */
    public const CATALOG = [
        'invitation_completed' => ['Un client a terminé un envoi', 'Clients et envois', true, false],
        'client_upload' => ['Un client a transmis un fichier', 'Clients et envois', true, false],
        'questionnaire_submitted' => ['Un client a soumis un questionnaire', 'Clients et envois', true, false],
        'mandate_signed' => ['Un client a signé son mandat', 'Clients et envois', true, false],
        'deadline_reminder' => ['Échéance qui approche', 'Échéances', true, false],
        'deadline_overdue' => ['Échéance dépassée', 'Échéances', true, false],
        'deadline_digest' => ['Récapitulatif quotidien des échéances (7 h)', 'Échéances', false, true],
        'appointment_booked' => ['Nouveau rendez-vous réservé', 'Rendez-vous', true, false],
        'appointment_rescheduled' => ['Rendez-vous déplacé', 'Rendez-vous', true, false],
        'appointment_cancelled' => ['Rendez-vous annulé', 'Rendez-vous', true, false],
        'invoice_paid' => ['Facture payée', 'Facturation', true, false],
        'data_request' => ['Demande de suppression de données d’un client (délai légal de 30 jours)', 'Conformité', true, true],
        'retention_upcoming' => ['Dossiers bientôt détruits (durée de conservation)', 'Conformité', true, false],
    ];

    /** Types qui n'existent que par courriel (pas d'entrée dans la cloche). */
    public const EMAIL_ONLY = ['deadline_digest'];

    public static function wants(Admin $admin, string $type, string $channel): bool
    {
        $default = self::CATALOG[$type] ?? [null, null, true, false];
        $prefs = $admin->notification_preferences ?? [];
        if (isset($prefs[$type][$channel])) {
            return (bool) $prefs[$type][$channel];
        }

        return (bool) ($channel === 'app' ? $default[2] : $default[3]);
    }

    /** Catalogue avec les choix de la personne, pour l'écran des préférences. */
    public static function forAdmin(Admin $admin): array
    {
        return collect(self::CATALOG)->map(fn ($def, $type) => [
            'type' => $type,
            'label' => $def[0],
            'group' => $def[1],
            'app' => in_array($type, self::EMAIL_ONLY, true) ? null : self::wants($admin, $type, 'app'),
            'email' => self::wants($admin, $type, 'email'),
        ])->values()->all();
    }

    /** Ne garde que les choix différents des valeurs par défaut. */
    public static function normalize(array $input): array
    {
        $out = [];
        foreach ($input as $type => $channels) {
            if (! isset(self::CATALOG[$type]) || ! is_array($channels)) {
                continue;
            }
            [, , $app, $email] = self::CATALOG[$type];
            foreach (['app' => $app, 'email' => $email] as $channel => $default) {
                if ($channel === 'app' && in_array($type, self::EMAIL_ONLY, true)) {
                    continue;
                }
                if (array_key_exists($channel, $channels) && (bool) $channels[$channel] !== $default) {
                    $out[$type][$channel] = (bool) $channels[$channel];
                }
            }
        }

        return $out;
    }
}
