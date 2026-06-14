<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'client_id',
        'dossier_id',
        'token',
        'status',
        'message',
        'expires_at',
        'sent_by',
        'sent_at',
        'submitted_at',
        'form_data',
        'pdf_filled_path',
        'validated_by',
        'validated_at',
        'rejection_reason',
        'email_sent',
        'email_error',
    ];

    protected $casts = [
        'form_data' => 'array',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'submitted_at' => 'datetime',
        'validated_at' => 'datetime',
        'email_sent' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'sent_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'validated_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }

    public function isAccessible(): bool
    {
        return !$this->isExpired() && !in_array($this->status, ['validated', 'rejected']);
    }

    public static function generateToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public static function statusOptions(): array
    {
        return [
            'pending' => 'En attente',
            'in_progress' => 'En cours',
            'submitted' => 'Soumis',
            'validated' => 'Validé',
            'rejected' => 'Rejeté',
        ];
    }
}
