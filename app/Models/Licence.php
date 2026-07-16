<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Licence extends Model
{
    protected $fillable = ['key','tenant_id','plan_id','kind','billing','deployment','device_limit',
        'features','addons','status','starts_at','expires_at','grace_days','amc_expires_at',
        'server_fingerprint','activated_at','last_validated_at'];
    protected $casts = ['features'=>'array','addons'=>'array','starts_at'=>'date','expires_at'=>'date',
        'amc_expires_at'=>'date','activated_at'=>'datetime','last_validated_at'=>'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function plan() { return $this->belongsTo(Plan::class); }
    public function devices() { return $this->hasMany(LicenceDevice::class); }
    public function activeDevices() { return $this->hasMany(LicenceDevice::class)->where('status', 'active'); }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->startOfDay()->gt($this->expires_at->copy()->addDays($this->grace_days));
    }

    public function amcActive(): bool
    {
        if ($this->kind !== 'perpetual') return true;
        return $this->amc_expires_at === null ? false : now()->startOfDay()->lte($this->amc_expires_at);
    }
}
