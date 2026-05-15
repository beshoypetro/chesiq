<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatternReviewSchedule extends Model
{
    protected $table = 'pattern_review_schedule';

    protected $fillable = [
        'user_id', 'failure_pattern_id',
        'ease_factor', 'interval_days', 'repetition_count',
        'due_at', 'last_reviewed_at',
    ];

    protected $casts = [
        'ease_factor' => 'float',
        'due_at' => 'date',
        'last_reviewed_at' => 'datetime',
    ];

    public function failurePattern(): BelongsTo
    {
        return $this->belongsTo(UserFailurePattern::class, 'failure_pattern_id');
    }
}
