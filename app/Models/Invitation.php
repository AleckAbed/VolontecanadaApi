<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'unique_code',
        'client_type',
        'client_id',
        'family_member_id',
        'dossier_id',
        'custom_name',
        'email',
        'phone',
        'message',
        'allow_uploads',
        'status',
        'sent_at',
        'expires_at',
        'completed_at',
        'sent_by',
        'email_sent',
        'email_error',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'email_sent' => 'boolean',
        'allow_uploads' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(FamilyMember::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'sent_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvitationItem::class)->orderBy('sort_order');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(InvitationUpload::class)->orderBy('created_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isAccessible(): bool
    {
        return !$this->isExpired() && $this->status !== 'completed';
    }

    public function recomputeStatus(): void
    {
        $items = $this->items()->get();
        if ($items->isEmpty()) return;

        $allCompleted = $items->every(fn ($i) => $i->status === 'completed');
        $anyStarted = $items->contains(fn ($i) => in_array($i->status, ['in_progress', 'completed']));

        if ($allCompleted) {
            $this->status = 'completed';
            $this->completed_at = $this->completed_at ?: now();
        } elseif ($anyStarted) {
            $this->status = 'in_progress';
        } else {
            $this->status = 'pending';
        }
        $this->save();
    }

    public static function generateUniqueCode(): string
    {
        do {
            $code = bin2hex(random_bytes(16));
        } while (self::where('unique_code', $code)->exists());

        return $code;
    }
}
