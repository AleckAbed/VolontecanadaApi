<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationAttachment extends Model
{
    protected $fillable = [
        'invitation_id',
        'dossier_supplementary_file_id',
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    public function supplementaryFile(): BelongsTo
    {
        return $this->belongsTo(DossierSupplementaryFile::class, 'dossier_supplementary_file_id');
    }
}
