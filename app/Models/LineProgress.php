<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineProgress extends Model
{
    protected $table = 'line_progress';

    protected $fillable = [
        'user_id',
        'line_id',
        'mastery',
        'attempts',
        'correct',
        'last_seen_at',
        'next_due_at',
    ];

    protected $casts = [
        'mastery'      => 'float',
        'attempts'     => 'integer',
        'correct'      => 'integer',
        'last_seen_at' => 'datetime',
        'next_due_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
