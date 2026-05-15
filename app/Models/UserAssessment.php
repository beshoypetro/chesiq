<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserAssessment extends Model
{
    protected $fillable = [
        'user_id',
        'started_at',
        'completed_at',
        'current_elo_estimate',
        'confidence_interval',
        'final_elo_estimate',
        'weakness_profile_json',
        'recommended_track',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'weakness_profile_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(AssessmentResponse::class, 'assessment_id');
    }
}
