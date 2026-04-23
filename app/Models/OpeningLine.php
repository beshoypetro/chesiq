<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningLine extends Model
{
    protected $fillable = [
        'line_id',
        'opening_name',
        'line_name',
        'eco',
        'color',
        'moves',
    ];

    protected $casts = [
        'moves' => 'array',
    ];
}
