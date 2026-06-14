<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'dossier_id',
        'collaborator_id',
        'label',
        'original_filename',
        'mime_type',
        'size',
        'path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(Collaborator::class);
    }
}
