<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserAiLevel extends Model
{
    protected $table = 'user_ai_level';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['user_id', 'skill_level', 'elo_estimate', 'updated_at'];
}
