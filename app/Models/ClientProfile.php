<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Profil d'immigration d'un client (family_member_id null) ou d'un membre de sa famille.
 * Voir App\Services\ClientProfileService.
 */
class ClientProfile extends Model
{
    use BelongsToCabinet;

    protected $fillable = ['client_id', 'family_member_id', 'data'];

    protected $casts = ['data' => 'array'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }
}
