<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One coach-led daily session per user per day (V3 P2). */
class CoachSession extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'completed_at',
        'steps_json',
    ];

    protected $casts = [
        'date' => 'date',
        'completed_at' => 'datetime',
        'steps_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
