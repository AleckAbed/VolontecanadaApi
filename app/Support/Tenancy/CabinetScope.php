<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtre toute requête Eloquent sur le cabinet courant.
 * Sans cabinet courant (et hors bypass), la requête ne renvoie rien.
 */
class CabinetScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (CurrentCabinet::isBypassed()) {
            return;
        }

        $cabinetId = CurrentCabinet::id();

        if ($cabinetId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('cabinet_id'), $cabinetId);
        // isBypassed() renvoie false dès qu'un cabinet est fixé (hors withoutScope) :
        // en console, CurrentCabinet::runAs() filtre donc aussi.
    }
}
