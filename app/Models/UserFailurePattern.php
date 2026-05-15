<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserFailurePattern extends Model
{
    protected $fillable = [
        'user_id',
        'pattern_kind',
        'opening_eco',
        'phase',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'last_game_ids_json',
        'sample_position_json',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_game_ids_json' => 'array',
        'sample_position_json' => 'array',
        'occurrence_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
