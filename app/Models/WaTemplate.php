<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaTemplate extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['last_test_at' => 'datetime', 'var_count' => 'integer'];
}
