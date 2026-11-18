<?php

namespace App\Models;

use App\Support\Tenancy\CurrentCabinet;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Jeton Sanctum : le propriétaire (admin, client, collaborateur) est chargé
 * SANS filtrage par cabinet, puisque c'est lui qui détermine le cabinet
 * courant. Le filtrage s'applique ensuite via ResolveCabinetFromUser.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    public function getTokenableAttribute()
    {
        if ($this->relationLoaded('tokenable')) {
            return $this->getRelation('tokenable');
        }

        $tokenable = CurrentCabinet::withoutScope(fn () => $this->tokenable()->first());
        $this->setRelation('tokenable', $tokenable);

        return $tokenable;
    }
}
