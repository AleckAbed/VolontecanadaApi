<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/**
 * Inscrit au journal d'activité (2.6) la création, la modification (noms des
 * champs seulement) et la suppression du modèle.
 */
trait Auditable
{
    /** Champs techniques dont la seule modification n'est pas une action à journaliser. */
    private static array $auditIgnored = [
        'updated_at', 'created_at', 'last_saved_at', 'last_login_at', 'remember_token',
        'access_token_hash', 'access_token_expires_at', 'access_token_purpose',
        'retention_notified_at', 'reminder_sent_at', 'email_sent', 'email_error', 'sent_at',
        'next_invoice_number', 'stripe_customer_id', 'stripe_subscription_id', 'subscription_status',
        'current_period_end', 'subscribed_seats', 'stripe_charges_enabled', 'google_oauth_state',
        'lock_screen_backgrounds', 'lock_screen_interval',
    ];

    public static function bootAuditable(): void
    {
        static::created(fn ($model) => Audit::forModel('created', $model));

        static::updated(function ($model) {
            $fields = array_values(array_diff(array_keys($model->getChanges()), self::$auditIgnored));
            if ($fields) {
                Audit::forModel('updated', $model, $fields);
            }
        });

        static::deleted(fn ($model) => Audit::forModel('deleted', $model));
    }
}
