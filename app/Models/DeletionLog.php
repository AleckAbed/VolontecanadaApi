<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;

/** Registre des destructions (3.9, 3.10) : quoi, quand, pourquoi, par qui — sans renseignement personnel. */
class DeletionLog extends Model
{
    use BelongsToCabinet;

    public const UPDATED_AT = null;

    public const REASONS = [
        'retention' => 'Durée de conservation écoulée',
        'erasure_request' => 'Demande de suppression du client',
        'manual' => 'Suppression par le cabinet',
    ];

    protected $fillable = ['cabinet_id', 'subject_type', 'subject_id', 'label', 'reason', 'files_deleted', 'performed_by'];
}
