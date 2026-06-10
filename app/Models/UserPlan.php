<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user weekly study plan produced by PlanGeneratorService (V2 Phase H).
 * Schema is intentionally thin — variability lives inside plan_json so we
 * can iterate on the LLM prompt without touching the table.
 */
class UserPlan extends Model
{
    protected $fillable = [
        'user_id',
        'generated_at',
        'trainer_id',
        'elo_at_generation',
        'intents_json',
        'plan_json',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'intents_json' => 'array',
            'plan_json' => 'array',
            'elo_at_generation' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
