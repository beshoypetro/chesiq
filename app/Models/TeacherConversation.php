<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeacherConversation extends Model
{
    protected $fillable = [
        'user_id',
        'started_at',
        'last_message_at',
        'summary_text',
        'summary_embedding',
        'referenced_game_ids_json',
        'referenced_pattern_ids_json',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_message_at' => 'datetime',
        'summary_embedding' => 'array',
        'referenced_game_ids_json' => 'array',
        'referenced_pattern_ids_json' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TeacherMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
