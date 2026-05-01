<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PuzzleSet extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'name', 'is_auto', 'share_token'];

    protected $casts = [
        'is_auto' => 'boolean',
    ];

    public function puzzles(): BelongsToMany
    {
        return $this->belongsToMany(Puzzle::class, 'puzzle_set_items', 'set_id', 'puzzle_id');
    }
}
