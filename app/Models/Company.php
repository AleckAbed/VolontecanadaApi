<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Client « entreprise » : employeur qui fait des demandes et envoie ses employés au cabinet. */
class Company extends Model
{
    use Auditable;

    use BelongsToCabinet;

    protected $fillable = ['name', 'business_number', 'sector', 'email', 'phone', 'website', 'address', 'city', 'province', 'postal_code', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class);
    }

    /** Employés, actuels et anciens (pivot : poste, début, fin). */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'company_client')
            ->withPivot(['id', 'job_title', 'started_at', 'ended_at', 'cabinet_id'])
            ->withTimestamps();
    }

    public function currentEmployees(): BelongsToMany
    {
        return $this->employees()->wherePivotNull('ended_at');
    }

    public function dossiers(): HasMany
    {
        return $this->hasMany(Dossier::class);
    }
}
