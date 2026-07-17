<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = ['uuid','company_name','contact_name','email','phone','gstin','state_code',
        'billing_address','address','country','currency','deployment','console_url','status','ecosystem_customer',
        'setup_fee_paid','terms_accepted_at','trial_ends_at','purge_after','notes'];
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
}
