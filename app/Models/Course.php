<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    protected $fillable = ['track_id', 'slug', 'name', 'description', 'display_order'];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class)->orderBy('display_order');
    }
}
