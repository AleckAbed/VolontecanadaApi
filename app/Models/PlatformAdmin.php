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

    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Double authentification par application, configurée sur le serveur (platform:two-factor). */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && ! empty($this->two_factor_secret);
    }
}
