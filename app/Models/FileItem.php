<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'folder_id',
        'name',
        'original_filename',
        'mime_type',
        'size',
        'is_favorite',
        'path',
        'created_by',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
