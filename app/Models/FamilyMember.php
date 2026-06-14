<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'relationship',
        'nationality',
        'country_of_residence',
        'address',
        'passport_number',
        'phone',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function dossiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Dossier::class, 'family_member_id');
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public static function relationshipOptions(): array
    {
        return [
            'conjoint' => 'Conjoint(e)',
            'enfant' => 'Enfant',
            'parent' => 'Parent',
            'frere_soeur' => 'Frère / Sœur',
            'autre' => 'Autre',
        ];
    }
}
