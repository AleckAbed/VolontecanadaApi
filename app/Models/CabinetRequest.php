<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Demande d'ouverture d'un cabinet (hors cabinet : aucune portée multi-cabinets). */
class CabinetRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    protected $fillable = [
        'cabinet_name', 'province', 'contact_name', 'email', 'phone', 'team_size', 'license_number', 'message', 'consent_at', 'ip',
    ];

    protected $hidden = ['ip'];

    protected function casts(): array
    {
        return ['consent_at' => 'datetime', 'decided_at' => 'datetime'];
    }
}
