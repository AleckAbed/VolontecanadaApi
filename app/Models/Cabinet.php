<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabinet d'immigration client de la plateforme (locataire du SaaS).
 */
class Cabinet extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }
}
