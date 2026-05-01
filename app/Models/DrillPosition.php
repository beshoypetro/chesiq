<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DrillPosition extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'fen', 'color', 'attempts', 'wins', 'draws', 'losses'];

    protected $casts = [
        'attempts' => 'integer',
        'wins' => 'integer',
        'draws' => 'integer',
        'losses' => 'integer',
    ];
}
