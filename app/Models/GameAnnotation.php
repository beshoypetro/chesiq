<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameAnnotation extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'game_id', 'move_ply', 'note', 'arrows_json', 'highlights_json', 'updated_at'];

    protected $casts = [
        'move_ply' => 'integer',
        'updated_at' => 'datetime',
    ];
}
