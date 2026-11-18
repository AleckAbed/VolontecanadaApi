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
        'province',
        'phone',
        'is_active',
        'gst_number',
        'qst_number',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'stripe_charges_enabled' => 'boolean',
        'subscribed_seats' => 'integer',
        'next_invoice_number' => 'integer',
    ];

    protected $hidden = ['stripe_customer_id', 'stripe_subscription_id', 'stripe_account_id'];

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    /** Abonnement en règle : payé, ou encore en période d'essai. */
    public function hasActiveSubscription(): bool
    {
        if ($this->subscription_status === 'active') {
            return true;
        }

        return $this->subscription_status === 'trialing'
            && ($this->trial_ends_at === null || $this->trial_ends_at->isFuture());
    }

    public function trialDaysLeft(): ?int
    {
        if ($this->subscription_status !== 'trialing' || ! $this->trial_ends_at) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($this->trial_ends_at->copy()->startOfDay(), false));
    }
}
