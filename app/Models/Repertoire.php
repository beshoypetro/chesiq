<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Repertoire extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'color', 'tree_json', 'updated_at'];

    protected $casts = [
        'updated_at' => 'datetime',
    ];
}
