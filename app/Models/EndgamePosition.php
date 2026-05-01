<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EndgamePosition extends Model
{
    public $timestamps = false;

    protected $fillable = ['fen', 'category', 'description', 'difficulty'];

    protected $casts = [
        'difficulty' => 'integer',
    ];
}
