<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentDraft extends Model
{
    protected $fillable = ['kind', 'topic', 'elo_band', 'payload', 'status', 'approved_by', 'approved_at'];

    protected $casts = [
        'payload' => 'array',
        'approved_at' => 'datetime',
    ];
}
