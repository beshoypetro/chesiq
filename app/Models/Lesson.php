<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    public $timestamps = false;

    protected $fillable = ['title', 'video_url', 'theme', 'level', 'puzzle_set_id'];
}
