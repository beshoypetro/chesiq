<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoveAnalysis extends Model
{
    protected $fillable = [
        'game_id',
        'move_number',
        'color',
        'move_san',
        'best_move_san',
        'classification',
        'cp_loss',
        'eval_before',
        'eval_after',
        'best_move_eval',
        'explanation',
        'best_move_line',
    ];

    protected $casts = [
        'best_move_line' => 'array',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
