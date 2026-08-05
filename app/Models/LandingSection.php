<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingSection extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content'    => 'array',
        'is_visible' => 'boolean',
        'is_layout'  => 'boolean',
        'sort'       => 'integer',
    ];
}
