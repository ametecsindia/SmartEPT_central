<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPerpetualBand extends Model
{
    protected $fillable = ['plan_id', 'min_users', 'max_users', 'price_inr', 'sort'];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
