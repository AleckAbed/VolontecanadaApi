<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class QuestionnaireRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'unique_code',
        'client_type',
        'client_id',
        'custom_name',
        'email',
        'phone',
        'form_type',
        'status',
        'form_data',
        'sent_at',
        'expires_at',
        'completed_at',
        'sent_by',
        'email_sent',
        'email_error',
    ];

    protected $casts = [
        'form_data' => 'array',
        'sent_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relation avec le client existant
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Relation avec l'admin qui a envoyé
     */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'sent_by');
    }

    /**
     * Vérifier si le formulaire est expiré
     */
    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Vérifier si le formulaire est complété
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Vérifier si le formulaire est en cours
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress' && !$this->isExpired();
    }

    /**
     * Générer un code unique
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = bin2hex(random_bytes(16)); // 32 caractères hexadécimaux
        } while (self::where('unique_code', $code)->exists());

        return $code;
    }
}



