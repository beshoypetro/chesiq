<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssessmentResponse extends Model
{
    protected $fillable = [
        'assessment_id',
        'position_id',
        'theme',
        'answer',
        'is_correct',
        'response_ms',
        'elo_before',
        'elo_after',
    ];

    protected $casts = [
        'answer' => 'array',
        'is_correct' => 'boolean',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(UserAssessment::class, 'assessment_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(AssessmentPosition::class, 'position_id');
    }
}
