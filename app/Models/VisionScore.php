<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisionScore extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'drill_type',
        'score',
        'accuracy',
        'created_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'accuracy' => 'float',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
