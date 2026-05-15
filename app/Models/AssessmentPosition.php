<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssessmentPosition extends Model
{
    protected $fillable = [
        'theme',
        'difficulty_band',
        'elo_target',
        'fen',
        'question_kind',
        'payload',
        'correct_answer',
        'explanation',
    ];

    protected $casts = [
        'payload' => 'array',
        'correct_answer' => 'array',
        'difficulty_band' => 'integer',
        'elo_target' => 'integer',
    ];
}
