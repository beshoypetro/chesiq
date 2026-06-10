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
        // V2 onboarding + trainer
        'lichess_username',
        'selected_trainer_id',
        'coaching_mode',
        'onboarding_step',
        'onboarding_completed_at',
        'intents',
        // Placement assessment outputs (written by AdaptiveAssessmentService)
        'placement_elo',
        'placement_track',
        'placement_completed_at',
        // User settings (Settings page)
        'preferences',
    ];

    /**
     * User preferences merged over defaults. `email_notifications` is derived
     * from the existing digest opt-out column so the weekly-digest plumbing
     * stays the single source of truth for email.
     *
     * @return array{email_notifications: bool, auto_analyze: bool, public_profile: bool}
     */
    public function resolvedPreferences(): array
    {
        $stored = is_array($this->preferences) ? $this->preferences : [];

        return [
            'email_notifications' => $this->digest_unsubscribed_at === null,
            'auto_analyze' => (bool) ($stored['auto_analyze'] ?? true),
            'public_profile' => (bool) ($stored['public_profile'] ?? false),
        ];
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    /**
     * Returns the persona line for the trainer this prompt should sound like.
     * Pass a $override (e.g. from the request body) to voice a different
     * character for one call without persisting the change — falls back to
     * the user's saved selection, then null if neither is set.
     */
    public function trainerPersona(?string $override = null): ?string
    {
        $id = $override ?: $this->selected_trainer_id;
        if (! $id) return null;
        return config("trainers.{$id}.persona");
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
            'onboarding_step' => 'integer',
            'onboarding_completed_at' => 'datetime',
            'intents' => 'array',
            'preferences' => 'array',
            'placement_elo' => 'integer',
            'placement_completed_at' => 'datetime',
        ];
    }
}
