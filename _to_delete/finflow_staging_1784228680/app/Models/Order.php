<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['number','quote_number','requested_by','tenant_id','licence_id','description','line_items','subtotal',
        'tax_amount','total','currency','gateway','gateway_order_id','gateway_payment_id','status',
        'manual_method','manual_reference','paid_at','provisioned_at','credit_due_date','recorded_by','meta'];
    protected $casts = ['line_items'=>'array','meta'=>'array','paid_at'=>'datetime',
        'provisioned_at'=>'datetime','credit_due_date'=>'date'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function licence() { return $this->belongsTo(Licence::class); }
    public function invoice() { return $this->hasOne(Invoice::class); }
    public function payments() { return $this->hasMany(OrderPayment::class); }
    public function recorder() { return $this->belongsTo(AdminUser::class, 'recorded_by'); }

    /** ₹ received so far — the ledger sum (rev186 lesson: computed, never stored). */
    public function received(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /** ₹ still outstanding against the order total (never below zero). */
    public function balance(): float
    {
        return round(max(0, (float) $this->total - $this->received()), 2);
    }
}
