<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PuzzleRushScore extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'score', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
