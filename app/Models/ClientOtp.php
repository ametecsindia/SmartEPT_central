<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientOtp extends Model
{
    protected $fillable = ['email', 'purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at'];
    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
