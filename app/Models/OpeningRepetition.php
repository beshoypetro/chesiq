<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningRepetition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'opening_key', 'move_uci', 'ease_factor',
        'interval_days', 'due_at', 'repetitions',
    ];

    protected $casts = [
        'due_at' => 'datetime',
    ];
}
