<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = ['course_id', 'slug', 'name', 'overview', 'display_order', 'published'];

    protected $casts = ['published' => 'boolean'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->orderBy('display_order');
    }
}
