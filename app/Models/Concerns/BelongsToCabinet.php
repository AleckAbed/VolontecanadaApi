<?php

namespace App\Models\Concerns;

use App\Models\Cabinet;
use App\Support\Tenancy\CabinetScope;
use App\Support\Tenancy\CurrentCabinet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Rattache un modèle à un cabinet :
 *  - filtre automatiquement toutes les requêtes sur le cabinet courant ;
 *  - remplit cabinet_id à la création ;
 *  - interdit de créer dans un autre cabinet ou de changer de cabinet.
 */
trait BelongsToCabinet
{
    public static function bootBelongsToCabinet(): void
    {
        static::addGlobalScope(new CabinetScope());

        static::creating(function ($model) {
            $current = CurrentCabinet::id();

            if (empty($model->cabinet_id)) {
                if ($current === null) {
                    throw new LogicException(sprintf(
                        'Création de %s sans cabinet courant ni cabinet_id.',
                        class_basename($model)
                    ));
                }
                $model->cabinet_id = $current;

                return;
            }

            if ($current !== null && (int) $model->cabinet_id !== $current && ! CurrentCabinet::isBypassed()) {
                throw new LogicException(sprintf(
                    'Création de %s dans le cabinet %d depuis le cabinet %d refusée.',
                    class_basename($model),
                    $model->cabinet_id,
                    $current
                ));
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('cabinet_id') && ! CurrentCabinet::isBypassed()) {
                throw new LogicException(sprintf('Changement de cabinet interdit pour %s.', class_basename($model)));
            }
        });
    }

    public function cabinet(): BelongsTo
    {
        return $this->belongsTo(Cabinet::class);
    }

    /** Requête sans filtrage par cabinet (connexion, résolution du cabinet). */
    public static function acrossCabinets(): Builder
    {
        return static::withoutGlobalScope(CabinetScope::class);
    }
}
