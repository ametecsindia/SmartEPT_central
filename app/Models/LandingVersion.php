<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
    ];
}
