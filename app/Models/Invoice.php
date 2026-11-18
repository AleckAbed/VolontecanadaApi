<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Facture d'un client du cabinet. Montants en cents (CAD).
 * Statuts : draft (brouillon) → sent (envoyée) → paid (payée) ; void (annulée).
 */
class Invoice extends Model
{
    use BelongsToCabinet;

    public const PAYMENT_METHODS = [
        'card' => 'Carte (en ligne)',
        'transfer' => 'Virement Interac',
        'cash' => 'Comptant',
        'cheque' => 'Chèque',
        'other' => 'Autre',
    ];

    protected $fillable = [
        'client_id', 'dossier_id', 'number', 'status', 'currency', 'tax_province',
        'subtotal_cents', 'tax_lines', 'tax_cents', 'total_cents', 'due_date', 'notes',
        'public_token', 'issued_at', 'paid_at', 'payment_method', 'payment_reference', 'created_by',
    ];

    protected $casts = [
        'tax_lines' => 'array',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function isPayable(): bool
    {
        return $this->status === 'sent' && $this->total_cents > 0;
    }
}
