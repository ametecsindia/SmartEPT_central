<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = ['uuid','company_name','contact_name','email','phone','gstin','state_code',
        'billing_address','address','country','currency','deployment','console_url','status','ecosystem_customer',
        'setup_fee_paid','terms_accepted_at','trial_ends_at','purge_after','storage_gb_override','notes'];
    protected $casts = ['ecosystem_customer'=>'boolean','setup_fee_paid'=>'boolean',
        'trial_ends_at'=>'datetime','purge_after'=>'datetime','terms_accepted_at'=>'datetime'];

    protected static function booted(): void
    {
        static::creating(function ($t) { $t->uuid = $t->uuid ?: (string) Str::uuid(); });
    }

    public function users() { return $this->hasMany(TenantUser::class); }
    public function licences() { return $this->hasMany(Licence::class); }
    public function orders() { return $this->hasMany(Order::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function storageUsage() { return $this->hasMany(StorageUsage::class); }
    public function activeLicence() { return $this->hasOne(Licence::class)->where('status', 'active')->latest('id'); }

    /** EPT-27 cloud storage governance: current usage vs plan quota (or per-tenant override). */
    public function storageStatus(): array
    {
        $used = (float) ($this->storageUsage()->latest('date')->value('gb_used') ?? 0);
        $quota = (float) ($this->storage_gb_override ?? $this->activeLicence?->plan?->storage_gb ?? 50);
        $pct = $quota > 0 ? (int) round($used / $quota * 100) : 0;
        $state = $pct >= 100 ? 'OVER' : ($pct >= 90 ? 'WARN' : 'OK');

        return ['used_gb' => round($used, 2), 'quota_gb' => (int) $quota, 'pct' => $pct, 'state' => $state];
    }
}
