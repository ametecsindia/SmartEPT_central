<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = ['number','tenant_id','order_id','date','due_date','line_items','subtotal','gst_rate',
        'gst_amount','cgst','sgst','igst','place_of_supply','buyer_gstin','sac_code','total','currency','status'];
    protected $casts = ['line_items'=>'array','date'=>'date','due_date'=>'date'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function order() { return $this->belongsTo(Order::class); }
}
