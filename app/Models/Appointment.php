<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rendez-vous réservé (dates en UTC).
 * Statuts : pending_payment (en attente du paiement en ligne) → confirmed ; cancelled.
 */
class Appointment extends Model
{
    use Auditable;

    use BelongsToCabinet;

    /** Une réservation payante non réglée libère son créneau après ce délai. */
    public const PAYMENT_HOLD_MINUTES = 30;

    protected $fillable = [
        'booking_service_id', 'host_admin_id', 'client_id', 'invoice_id', 'name', 'email', 'phone', 'notes',
        'starts_at', 'ends_at', 'status', 'meeting_mode', 'meeting_url', 'google_event_id', 'public_token',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(BookingService::class, 'booking_service_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'host_admin_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Rendez-vous qui occupent réellement un créneau. */
    public function scopeBlocking(Builder $q): Builder
    {
        return $q->where(function ($q) {
            $q->where('status', 'confirmed')
                ->orWhere(fn ($q) => $q->where('status', 'pending_payment')
                    ->where('created_at', '>', now()->subMinutes(self::PAYMENT_HOLD_MINUTES)));
        });
    }
}
