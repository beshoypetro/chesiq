<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Game extends Model
{
    protected $fillable = [
        'user_id',
        'chess_com_game_id',
        'pgn',
        'white_username',
        'black_username',
        'white_rating',
        'black_rating',
        'user_color',
        'result',
        'time_class',
        'time_control',
        'opening_name',
        'eco_code',
        'white_accuracy',
        'black_accuracy',
        'move_count',
        'played_at',
        'analyzed_at',
    ];

    protected $casts = [
        'played_at'      => 'datetime',
        'analyzed_at'    => 'datetime',
        'white_accuracy' => 'float',
        'black_accuracy' => 'float',
        'white_rating'   => 'integer',
        'black_rating'   => 'integer',
        'move_count'     => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moveAnalyses(): HasMany
    {
        return $this->hasMany(MoveAnalysis::class)->orderBy('move_number');
    }

    public function getUserAccuracyAttribute(): ?float
    {
        if ($this->user_color === 'white') {
            return $this->white_accuracy;
        }
        return $this->black_accuracy;
    }
}
