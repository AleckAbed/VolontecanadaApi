<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** Contact d'une entreprise cliente (RH, gestionnaire), avec son accès à l'espace entreprise. */
class CompanyContact extends Authenticatable
{
    use Auditable;

    use BelongsToCabinet;

    use HasApiTokens;

    protected $fillable = ['company_id', 'first_name', 'last_name', 'email', 'phone', 'job_title', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'access_token_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'access_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
