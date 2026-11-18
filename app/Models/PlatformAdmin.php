<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte de l'éditeur de la plateforme (super-admin).
 * N'appartient à aucun cabinet : voit la liste des cabinets et peut les suspendre.
 * Création : php artisan platform:create-admin
 */
class PlatformAdmin extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
