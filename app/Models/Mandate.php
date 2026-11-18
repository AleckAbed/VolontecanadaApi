<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mandat de représentation envoyé au client et signé en ligne.
 * Le texte est figé à l'envoi ; body_hash (SHA-256) prouve qu'il n'a pas changé depuis.
 */
class Mandate extends Model
{
    use BelongsToCabinet;

    protected $fillable = [
        'client_id', 'dossier_id', 'appointment_id', 'title', 'body', 'body_hash', 'status',
        'public_token', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }
}
