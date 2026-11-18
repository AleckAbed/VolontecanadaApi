<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Model;

/** Notification d'un membre du personnel (cloche de l'en-tête). */
class AdminNotification extends Model
{
    use BelongsToCabinet;

    protected $fillable = ['admin_id', 'type', 'title', 'body', 'link', 'dedupe_key'];

    protected $casts = ['read_at' => 'datetime'];
}
