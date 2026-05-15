<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmCallLog extends Model
{
    public $timestamps = false;
    protected $table = 'llm_call_log';

    protected $fillable = [
        'user_id', 'endpoint', 'model',
        'tokens_in', 'tokens_out', 'cost_estimate', 'cache_hit', 'created_at',
    ];

    protected $casts = [
        'cache_hit' => 'boolean',
        'created_at' => 'datetime',
        'cost_estimate' => 'float',
    ];
}
