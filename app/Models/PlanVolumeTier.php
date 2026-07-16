<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanVolumeTier extends Model
{
    protected $fillable = ['plan_id','min_devices','max_devices','rate_inr_annual'];
    public function plan() { return $this->belongsTo(Plan::class); }
}
