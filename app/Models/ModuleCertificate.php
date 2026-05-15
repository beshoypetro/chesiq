<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleCertificate extends Model
{
    protected $fillable = ['user_id', 'module_id', 'serial', 'issued_at'];
    protected $casts = ['issued_at' => 'datetime'];
}
