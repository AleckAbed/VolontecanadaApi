<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'invitation_id',
        'label',
        'original_filename',
        'mime_type',
        'size',
        'path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }
}
