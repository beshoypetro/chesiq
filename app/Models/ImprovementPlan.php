<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable, long-horizon improvement plan — one row per user. The structural
 * state (milestone path, skill mastery, next action, readiness, adaptation
 * log) is recomputed deterministically by ImprovementPlanService::sync().
 *
 * Sibling to UserPlan: UserPlan is the weekly 7-day-expiry artifact this plan
 * embeds as "this week's tasks"; ImprovementPlan is the living object.
 */
class ImprovementPlan extends Model
{
    protected $fillable = [
        'user_id',
        'placement_elo_at_creation',
        'current_milestone_slug',
        'milestones_json',
        'skill_mastery_json',
        'next_action_json',
        'readiness_score',
        'trainer_id',
        'trainer_intro',
        'adaptation_log_json',
        'signals_hash',
        'generated_at',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'milestones_json' => 'array',
            'skill_mastery_json' => 'array',
            'next_action_json' => 'array',
            'adaptation_log_json' => 'array',
            'readiness_score' => 'integer',
            'placement_elo_at_creation' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
