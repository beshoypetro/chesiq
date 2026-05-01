<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrillQueue extends Model
{
    public $timestamps = false;

    protected $table = 'drill_queue';

    protected $fillable = ['user_id', 'game_id', 'move_ply', 'fen', 'best_move', 'added_at', 'solved_at'];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
