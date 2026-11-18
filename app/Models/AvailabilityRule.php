<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;

/** Plage de disponibilité hebdomadaire d'un membre du personnel, en heure locale du cabinet. */
class AvailabilityRule extends Model
{
    use BelongsToCabinet;

    protected $fillable = ['admin_id', 'weekday', 'start_time', 'end_time'];

    protected $casts = ['weekday' => 'integer'];
}
