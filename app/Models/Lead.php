<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** R3-7: sales lead (public capture form, WhatsApp, referrals, manual entry). */
class Lead extends Model
{
    public const STATUSES = ['NEW', 'CONTACTED', 'DEMO_SCHEDULED', 'QUOTED', 'WON', 'LOST'];

    protected $fillable = ['name', 'company', 'email', 'phone', 'city', 'devices_interested',
        'source', 'message', 'status', 'notes', 'follow_up_at', 'assigned_to', 'tenant_id'];

    protected $casts = ['follow_up_at' => 'datetime'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
