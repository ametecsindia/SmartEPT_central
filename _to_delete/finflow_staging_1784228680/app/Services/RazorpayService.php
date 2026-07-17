<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Razorpay via direct REST API — no SDK dependency.
 * Keys live in Settings (razorpay_key_id / razorpay_key_secret / razorpay_webhook_secret).
 * Test mode works with rzp_test_* keys.
 */
class RazorpayService
{
    private const BASE = 'https://api.razorpay.com/v1';

    public function enabled(): bool
    {
        return Setting::get('razorpay_key_id') && Setting::get('razorpay_key_secret');
    }

    public function keyId(): ?string
    {
        return Setting::get('razorpay_key_id');
    }

    /**
     * Create a Razorpay order for one of our orders. Amount in paise.
     * $amount defaults to the order's OUTSTANDING BALANCE (master prompt §10):
     * a partially-paid credit order collects only what is still owed, and if an
     * offline entry lands between order-create and completion, the charged
     * amount is read back from Razorpay (authoritative) at record time.
     */
    public function createOrder(Order $order, ?float $amount = null): array
    {
        $amount = $amount ?? $order->balance();

        $response = Http::withBasicAuth(Setting::get('razorpay_key_id'), Setting::get('razorpay_key_secret'))
            ->post(self::BASE . '/orders', [
                'amount' => (int) round($amount * 100),
                'currency' => $order->currency,
                'receipt' => $order->number,
                'notes' => ['tenant' => $order->tenant->company_name, 'order' => $order->number],
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $response->json('error.description') ?? $response->body()];
        }

        $order->update(['gateway' => 'razorpay', 'gateway_order_id' => $response->json('id')]);

        return ['ok' => true, 'razorpay_order_id' => $response->json('id'), 'key_id' => $this->keyId(), 'amount' => $amount];
    }

    /**
     * The amount (₹) actually charged on a Razorpay order — the authoritative
     * figure for the payments ledger. Falls back to null on any API hiccup.
     */
    public function fetchOrderAmount(string $razorpayOrderId): ?float
    {
        try {
            $response = Http::withBasicAuth(Setting::get('razorpay_key_id'), Setting::get('razorpay_key_secret'))
                ->get(self::BASE . '/orders/' . $razorpayOrderId);

            return $response->successful() ? ((int) $response->json('amount')) / 100 : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Verify checkout callback signature (order_id|payment_id HMAC).
     */
    public function verifyPaymentSignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, Setting::get('razorpay_key_secret', ''));

        return hash_equals($expected, $signature);
    }

    /**
     * Verify webhook signature (raw body HMAC with webhook secret).
     */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = Setting::get('razorpay_webhook_secret', '');

        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
