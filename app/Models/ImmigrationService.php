<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImmigrationService extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'category', 'duration', 'color', 'status', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];
}
