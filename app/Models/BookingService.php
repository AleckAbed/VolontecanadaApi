<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prestation réservable en ligne (consultation, suivi…). Prix en cents, 0 = gratuite. */
class BookingService extends Model
{
    use BelongsToCabinet;

    public const MEETING_MODES = [
        'video' => 'Visioconférence',
        'phone' => 'Téléphone',
        'in_person' => 'En personne',
    ];

    protected $fillable = [
        'host_admin_id', 'name', 'description', 'duration_minutes', 'price_cents', 'taxable',
        'meeting_mode', 'location', 'send_mandate', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'price_cents' => 'integer',
        'taxable' => 'boolean',
        'send_mandate' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'host_admin_id');
    }
}
