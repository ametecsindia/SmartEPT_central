<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = ['uuid','company_name','contact_name','email','phone','gstin','state_code',
        'billing_address','address','country','currency','deployment','console_url','status','ecosystem_customer',
        'setup_fee_paid','terms_accepted_at','trial_ends_at','purge_after','storage_gb_override','storage_alert_level','notes','console_slug'];
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

    /**
     * The tenant's allotted storage in GB — the single source of truth used both to
     * meter usage (storageStatus) and to push the cap to the client's console
     * (ProductProvisioner). Order of precedence:
     *   1. Per-client override (set on the tenant screen) — an exact allowance.
     *   2. The plan's included GB, when the plan sets one (> 0).
     *   3. Per-user policy — seats x per-user free storage (Setting storage_per_user_gb,
     *      default 1 GB). This is the active model: the 'smartept' plan uses storage_gb = 0.
     * Returns 0.0 only when there's no override, no plan GB, and no licence seats yet.
     */
    public function storageQuotaGb(): float
    {
        $override = $this->storage_gb_override;
        if ($override !== null && (float) $override > 0) {
            return (float) $override;
        }
        $planGb = (float) ($this->activeLicence?->plan?->storage_gb ?? 0);
        if ($planGb > 0) {
            return $planGb;
        }
        $seats = (int) ($this->activeLicence?->device_limit ?: 0);
        $perUser = (float) (Setting::get('storage_per_user_gb') ?: 1);

        return $seats * $perUser;
    }

    /** EPT-27 cloud storage governance: current usage vs the allotted quota. */
    public function storageStatus(): array
    {
        $used = (float) ($this->storageUsage()->latest('date')->value('gb_used') ?? 0);
        $quota = $this->storageQuotaGb();
        $pct = $quota > 0 ? (int) round($used / $quota * 100) : 0;
        $state = $pct >= 100 ? 'OVER' : ($pct >= 90 ? 'WARN' : 'OK');
        $auto = ($this->storage_gb_override === null || (float) $this->storage_gb_override <= 0);

        return ['used_gb' => round($used, 2), 'quota_gb' => (int) round($quota), 'pct' => $pct, 'state' => $state, 'auto' => $auto];
    }
}
