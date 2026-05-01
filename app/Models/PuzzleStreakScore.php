<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PuzzleStreakScore extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'length', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
