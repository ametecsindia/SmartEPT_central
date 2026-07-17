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
        'min_devices', 'exclusive_email', 'valid_from', 'valid_until', 'active'];

    protected $casts = ['valid_from' => 'date', 'valid_until' => 'date', 'active' => 'boolean'];

    /**
     * Look up a coupon and check every rule. Returns [coupon|null, reason|null].
     * $email: the buyer's email — needed for exclusive-to-one-email coupons.
     */
    public static function check(?string $code, int $devices = 0, ?string $email = null): array
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
        if ($coupon->exclusive_email
            && strcasecmp(trim((string) $email), trim($coupon->exclusive_email)) !== 0) {
            // An exclusive coupon behaves like an unknown code for everyone else —
            // it must never leak whose offer it is.
            return [null, 'unknown_code'];
        }

        return [$coupon, null];
    }

    /**
     * Exclusive-offer catch (blueprint §6): the newest usable coupon that was
     * sent exclusively to this email — quietly auto-applied at signup/cart.
     */
    public static function exclusiveFor(?string $email): ?self
    {
        $email = trim((string) $email);
        if ($email === '') {
            return null;
        }

        return static::whereRaw('LOWER(exclusive_email) = ?', [mb_strtolower($email)])
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()->startOfDay()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->startOfDay()))
            ->where(fn ($q) => $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses'))
            ->latest('id')
            ->first();
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
