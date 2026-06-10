<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleProgress extends Model
{
    protected $table = 'module_progress';

    protected $fillable = [
        'user_id',
        'module_id',
        'started_at',
        'completed_at',
        'activities_completed',
        'activities_total',
        'completed_activity_ids_json',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'activities_completed' => 'integer',
        'activities_total' => 'integer',
        'completed_activity_ids_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
