<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'avatar',
        'description',
        'website',
        'followers_count',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'followers_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(NewsArticle::class, 'source_id');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(Admin::class, 'news_source_followers', 'source_id', 'admin_id')
            ->withTimestamps();
    }
}
