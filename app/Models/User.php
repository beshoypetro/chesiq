<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'chess_com_username',
        'last_synced_at',
        // F008
        'current_streak',
        'last_active_date',
        // F030
        'digest_unsubscribed_at',
        // F031
        'style_profile_json',
    ];

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_synced_at' => 'datetime',
            'is_admin' => 'boolean',
            'digest_unsubscribed_at' => 'datetime',
            'last_active_date' => 'date',
            'current_streak' => 'integer',
        ];
    }
}
