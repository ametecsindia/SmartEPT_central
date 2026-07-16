<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Stripe via direct REST API — no SDK dependency.
 * Keys live in Settings (stripe_secret_key / stripe_webhook_secret).
 * Test mode works with sk_test_* keys. Used for international (USD) customers.
 */
class StripeService
{
    private const BASE = 'https://api.stripe.com/v1';

    public function enabled(): bool
    {
        return (bool) Setting::get('stripe_secret_key');
    }

    /**
     * Create a hosted Checkout Session and return its redirect URL.
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $response = Http::withToken(Setting::get('stripe_secret_key'))
            ->asForm()
            ->post(self::BASE . '/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $order->number,
                'customer_email' => $order->tenant->email,
                'line_items[0][price_data][currency]' => strtolower($order->currency),
                'line_items[0][price_data][product_data][name]' => 'SmartEPT — ' . $order->description,
                'line_items[0][price_data][unit_amount]' => (int) round($order->total * 100),
                'line_items[0][quantity]' => 1,
                'metadata[order_number]' => $order->number,
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'error' => $response->json('error.message') ?? $response->body()];
        }

        $order->update(['gateway' => 'stripe', 'gateway_order_id' => $response->json('id')]);

        return ['ok' => true, 'url' => $response->json('url'), 'session_id' => $response->json('id')];
    }

    /**
     * Verify a Stripe webhook signature header: t=...,v1=...
     */
    public function verifyWebhookSignature(string $rawBody, string $signatureHeader, int $tolerance = 300): bool
    {
        $secret = Setting::get('stripe_webhook_secret', '');

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $timestamp = null;
        $v1 = null;

        foreach (explode(',', $signatureHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = $v;
            }
            if ($k === 'v1') {
                $v1 = $v;
            }
        }

        if (! $timestamp || ! $v1 || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, $v1);
    }
}
