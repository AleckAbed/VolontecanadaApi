<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dossier extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'scope', // client | member | family
        'family_member_id',
        'name',
        'status',
        'opened_at',
        'deadline_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'date',
            'deadline_at' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    public static function scopeOptions(): array
    {
        return [
            'client' => 'Client seul',
            'member' => 'Un membre de la famille',
            'family' => 'Toute la famille',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            'en_cours' => 'En cours',
            'soumis' => 'Soumis',
            'accorde' => 'Accordé',
            'refuse' => 'Refusé',
            'annule' => 'Annulé',
        ];
    }

    /** Human-readable label for who the dossier belongs to */
    public function getScopeLabelAttribute(): string
    {
        if ($this->scope === 'client') {
            return $this->client?->full_name ?? 'Client';
        }
        if ($this->scope === 'member' && $this->familyMember) {
            return $this->familyMember->full_name;
        }
        if ($this->scope === 'family') {
            return 'Famille entière';
        }
        return $this->scope;
    }
}
