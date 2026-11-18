<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'une entreprise : ouvrir le dossier d'un employé. Le cabinet l'accepte ou la refuse. */
class CompanyRequest extends Model
{
    use Auditable;

    use BelongsToCabinet;

    protected $fillable = ['company_id', 'contact_id', 'first_name', 'last_name', 'email', 'phone', 'job_title', 'request_type', 'with_family', 'message', 'status', 'response', 'client_id', 'dossier_id', 'handled_by', 'handled_at'];

    protected $casts = ['with_family' => 'boolean', 'handled_at' => 'datetime'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CompanyContact::class, 'contact_id');
    }
}
