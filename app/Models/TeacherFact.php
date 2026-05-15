<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherFact extends Model
{
    protected $fillable = [
        'user_id',
        'fact_text',
        'confidence',
        'source_conversation_id',
        'superseded_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'superseded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(TeacherConversation::class, 'source_conversation_id');
    }
}
