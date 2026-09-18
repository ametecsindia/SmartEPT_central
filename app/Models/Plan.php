<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    // 18-Sep-2026: 'tier' added (Standard/Enforcer/Commander plans) — a plain
    // nullable string alongside the existing 'code', not a replacement for it.
    protected $fillable = ['code','tier','name','inr_annual','inr_monthly','usd_annual','usd_monthly',
        'perpetual_device_inr','perpetual_server_inr','min_devices','storage_gb','features','sort','active'];
    protected $casts = ['features'=>'array','active'=>'boolean','storage_gb'=>'integer'];

    public function volumeTiers() { return $this->hasMany(PlanVolumeTier::class)->orderBy('min_devices'); }
    public function perpetualBands() { return $this->hasMany(PlanPerpetualBand::class)->orderBy('min_users'); }
    public function licences() { return $this->hasMany(Licence::class); }
}
