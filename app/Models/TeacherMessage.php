<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'mode',
        'content',
        'voice_audio_url',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TeacherConversation::class, 'conversation_id');
    }
}
