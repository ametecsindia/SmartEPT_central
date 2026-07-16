<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * R3-7: discount coupon. Validation lives here so signup, portal and admin
 * all apply exactly the same rules; usage is counted only when an order is PAID.
 */
class Coupon extends Model
{
    protected $fillable = ['code', 'description', 'type', 'value', 'max_uses', 'used_count',
        'min_devices', 'valid_from', 'valid_until', 'active'];

    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date', 'active' => 'boolean'];

    /** Look up a coupon and check every rule. Returns [coupon|null, reason|null]. */
    public static function check(?string $code, int $devices = 0): array
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return [null, null];
        }

        $coupon = static::where('code', $code)->first();

        if (! $coupon) {
            return [null, 'unknown_code'];
        }
        if (! $coupon->active) {
            return [null, 'inactive'];
        }
        if ($coupon->valid_from && now()->startOfDay()->lt($coupon->valid_from)) {
            return [null, 'not_started'];
        }
        if ($coupon->valid_until && now()->startOfDay()->gt($coupon->valid_until)) {
            return [null, 'expired'];
        }
        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            return [null, 'fully_used'];
        }
        if ($coupon->min_devices !== null && $devices < $coupon->min_devices) {
            return [null, 'needs_' . $coupon->min_devices . '_devices'];
        }

        return [$coupon, null];
    }

    /** ₹ discount for a given subtotal (never exceeds the subtotal). */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->type === 'percent'
            ? round($subtotal * (float) $this->value / 100, 2)
            : (float) $this->value;

        return round(min($discount, $subtotal), 2);
    }
}
