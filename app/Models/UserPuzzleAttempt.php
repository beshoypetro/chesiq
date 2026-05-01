<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPuzzleAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'puzzle_id', 'solved', 'time_ms', 'time_limit_ms', 'created_at'];
}
