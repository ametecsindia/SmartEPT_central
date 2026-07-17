<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per rupee-event against an order — gateway captures, NEFT/UPI/cheque
 * entries, credit instalments. Partial/paid state is COMPUTED from this ledger
 * (sum vs order total) so the books can never disagree with the money.
 */
class OrderPayment extends Model
{
    protected $fillable = ['order_id', 'amount', 'gateway', 'method', 'reference',
        'gateway_payment_id', 'recorded_by', 'note', 'paid_at'];

    protected $casts = ['amount' => 'float', 'paid_at' => 'datetime'];

    public function order() { return $this->belongsTo(Order::class); }
    public function recorder() { return $this->belongsTo(AdminUser::class, 'recorded_by'); }
}
