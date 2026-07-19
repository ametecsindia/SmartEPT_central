<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = ['code','name','inr_annual','inr_monthly','usd_annual','usd_monthly',
        'perpetual_device_inr','perpetual_server_inr','min_devices','storage_gb','features','sort','active'];
    protected $casts = ['features'=>'array','active'=>'boolean','storage_gb'=>'integer'];

    public function volumeTiers() { return $this->hasMany(PlanVolumeTier::class)->orderBy('min_devices'); }
    public function licences() { return $this->hasMany(Licence::class); }
}
