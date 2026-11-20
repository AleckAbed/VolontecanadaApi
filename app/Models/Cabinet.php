<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabinet d'immigration client de la plateforme (locataire du SaaS).
 */
class Cabinet extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'slug',
        'province',
        'phone',
        'is_active',
        'gst_number',
        'qst_number',
        'timezone',
        'booking_enabled',
        'booking_intro',
        'mandate_text',
        'brand_color',
        'website',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'booking_enabled' => 'boolean',
        'trial_ends_at' => 'datetime',
        'current_period_end' => 'datetime',
        'stripe_charges_enabled' => 'boolean',
        'subscribed_seats' => 'integer',
        'next_invoice_number' => 'integer',
        'module_access' => 'array',
    ];

    protected $hidden = ['stripe_customer_id', 'stripe_subscription_id', 'stripe_account_id'];

    /** Couleur par défaut quand le cabinet n'en a pas choisi. */
    public const DEFAULT_COLOR = '#2563eb';

    /**
     * Image de marque (1.9) : nom, logo (URL publique) et couleurs, pour les courriels,
     * le portail client et les pages publiques.
     *
     * @return array{name:string, logo_url:?string, color:string, color_dark:string, website:?string}
     */
    public function branding(): array
    {
        $color = $this->brand_color ?: self::DEFAULT_COLOR;

        return [
            'name' => $this->name,
            'logo_url' => $this->logo_path
                ? rtrim((string) config('app.url'), '/') . "/api/cabinets/{$this->slug}/logo?v=" . substr(md5($this->logo_path), 0, 8)
                : null,
            'color' => $color,
            'color_dark' => self::darken($color),
            'website' => $this->website,
        ];
    }

    /** Assombrit une couleur #rrggbb de 20 % (dégradés, survol). */
    public static function darken(string $hex, float $factor = 0.8): string
    {
        $hex = ltrim($hex, '#');
        $rgb = array_map(fn ($i) => (int) round(hexdec(substr($hex, $i, 2)) * $factor), [0, 2, 4]);

        return sprintf('#%02x%02x%02x', ...$rgb);
    }

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
