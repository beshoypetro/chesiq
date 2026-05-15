<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Track extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'elo_min', 'elo_max', 'display_order', 'published'];

    protected $casts = ['published' => 'boolean'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class)->orderBy('display_order');
    }
}
