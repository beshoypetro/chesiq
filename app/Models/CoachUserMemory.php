<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachUserMemory extends Model
{
    protected $table = 'coach_user_memory';

    protected $fillable = [
        'user_id',
        'tag',
        'note',
        'weight',
    ];

    protected $casts = [
        'weight' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
