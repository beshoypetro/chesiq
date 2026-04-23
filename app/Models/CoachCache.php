<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachCache extends Model
{
    protected $table = 'coach_cache';

    protected $fillable = [
        'cache_key',
        'response_text',
    ];
}
