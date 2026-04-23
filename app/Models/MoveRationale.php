<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoveRationale extends Model
{
    protected $fillable = [
        'line_id',
        'move_index',
        'san',
        'text',
        'source',
    ];

    protected $casts = [
        'move_index' => 'integer',
    ];
}
