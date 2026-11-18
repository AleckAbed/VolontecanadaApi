<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCabinet;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use BelongsToCabinet;

    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // super_admin, admin, manager, etc.
        'is_active',
        'lock_screen_backgrounds',
        'lock_screen_interval',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'access_token_hash',
        'google_refresh_token',
        'google_oauth_state',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'lock_screen_backgrounds' => 'array',
            'lock_screen_interval' => 'integer',
            'access_token_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
            // Jeton Google Agenda chiffré avec APP_KEY : illisible en cas de fuite de la base seule.
            'google_refresh_token' => 'encrypted',
        ];
    }

    public function followedSources(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(NewsSource::class, 'news_source_followers', 'admin_id', 'source_id')
            ->withTimestamps();
    }
}


