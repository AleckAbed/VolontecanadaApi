<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dossier extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'scope', // client | member | family
        'family_member_id',
        'collaborator_id',
        'collab_access_revoked',
        'name',
        'service_name',
        'status',
        'opened_at',
        'deadline_at',
        'notes',
        'allow_collab_uploads',
        'send_base_docs_to_client',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'date',
            'deadline_at' => 'date',
            'allow_collab_uploads' => 'boolean',
            'send_base_docs_to_client' => 'boolean',
            'collab_access_revoked' => 'boolean',
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

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DossierDocument::class)->orderBy('sort_order');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(DossierUpload::class)->orderBy('created_at');
    }

    public function supplementaryFiles(): HasMany
    {
        return $this->hasMany(DossierSupplementaryFile::class)->orderByDesc('created_at');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
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
            'rejete' => 'Rejeté',
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
