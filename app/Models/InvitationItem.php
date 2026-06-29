<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invitation_id',
        'item_kind',
        'form_type_id',
        'document_template_id',
        'dossier_document_id',
        'linked_questionnaire_code',
        'status',
        'form_data',
        'pdf_filled_path',
        'sort_order',
        'started_at',
        'completed_at',
        'last_saved_at',
    ];

    protected $casts = [
        'form_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_saved_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function formType(): BelongsTo
    {
        return $this->belongsTo(FormType::class);
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function dossierDocument(): BelongsTo
    {
        return $this->belongsTo(DossierDocument::class);
    }

    public function markStarted(): void
    {
        if ($this->status === 'pending') {
            $this->status = 'in_progress';
            $this->started_at = now();
        }
        $this->last_saved_at = now();
        $this->save();
    }

    public function markCompleted(): void
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->last_saved_at = now();
        $this->save();
    }
}
