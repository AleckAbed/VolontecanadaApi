<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Échéance saisie par le cabinet. Voir App\Services\DeadlineService pour les
 * échéances déduites (date limite de dossier, expirations du profil client).
 */
class Deadline extends Model
{
    use BelongsToCabinet;

    public const TYPES = [
        'filing' => 'Date limite de dépôt',
        'biometrics' => 'Biométrie',
        'medical' => 'Examen médical',
        'documents' => 'Documents à fournir',
        'permit_expiry' => 'Expiration de permis ou de statut',
        'passport_expiry' => 'Expiration du passeport',
        'interview' => 'Entrevue',
        'other' => 'Autre',
    ];

    protected $fillable = ['dossier_id', 'client_id', 'type', 'label', 'due_date', 'remind_days', 'notes', 'created_by'];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'remind_days' => 'integer',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
