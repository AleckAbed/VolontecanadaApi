<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImmigrationService extends Model
{
    use BelongsToCabinet;

    use HasFactory;

    protected $fillable = [
        'name', 'description', 'category', 'duration', 'color', 'status', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
