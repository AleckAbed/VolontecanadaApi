<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'dossier_id',
        'document_template_id',
        'doc_type', // ircc | fo
        'name',
        'description',
        'template_path',
        'schema',
        'form_data',
        'filled_pdf_path',
        'status',
        'last_saved_at',
        'completed_at',
        'sort_order',
    ];

    protected $casts = [
        'schema' => 'array',
        'form_data' => 'array',
        'last_saved_at' => 'datetime',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function markCompleted(): void
    {
        $this->status = 'completed';
        $this->completed_at = $this->completed_at ?: now();
        $this->save();
    }
}
