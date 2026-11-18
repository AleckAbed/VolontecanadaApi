<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'un client au sujet de ses renseignements (3.10) : suppression. */
class DataRequest extends Model
{
    use Auditable;

    use BelongsToCabinet;

    protected $fillable = ['client_id', 'type', 'client_name', 'client_email', 'message', 'status', 'response', 'handled_by', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
