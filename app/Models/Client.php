<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'date_of_birth',
        'nationality',
        'passport_number',
        'address',
        'is_active',
        'client_type', // 'single' | 'family'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the full name of the client.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Family members (when client_type = 'family').
     */
    public function familyMembers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(FamilyMember::class);
    }

    /**
     * Immigration dossiers (client can have multiple).
     */
    public function dossiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Dossier::class);
    }

    public function isFamily(): bool
    {
        return $this->client_type === 'family';
    }
}


