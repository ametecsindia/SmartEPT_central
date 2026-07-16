<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = ['number','quote_number','requested_by','tenant_id','licence_id','description','line_items','subtotal',
        'tax_amount','total','currency','gateway','gateway_order_id','gateway_payment_id','status',
        'manual_method','manual_reference','paid_at','recorded_by','meta'];
    protected $casts = ['line_items'=>'array','meta'=>'array','paid_at'=>'datetime'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function licence() { return $this->belongsTo(Licence::class); }
    public function invoice() { return $this->hasOne(Invoice::class); }
    public function recorder() { return $this->belongsTo(AdminUser::class, 'recorded_by'); }
}
