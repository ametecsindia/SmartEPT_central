<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WebhookEvent;
use App\Services\BillingService;
use App\Services\RazorpayService;
use App\Services\StripeService;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function razorpay(Request $request, RazorpayService $razorpay, BillingService $billing)
    {
        $raw = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature', '');

        if (! $razorpay->verifyWebhookSignature($raw, $signature)) {
            return response()->json(['error' => 'bad signature'], 400);
        }

        $payload = $request->json()->all();
        $event = WebhookEvent::create([
            'gateway' => 'razorpay',
            'event_type' => $payload['event'] ?? 'unknown',
            'event_id' => $request->header('x-razorpay-event-id'),
            'payload' => $payload,
        ]);

        try {
            if (($payload['event'] ?? '') === 'payment.captured') {
                $entity = $payload['payload']['payment']['entity'] ?? [];
                $order = Order::where('gateway_order_id', $entity['order_id'] ?? '')->first();
                if ($order) {
                    // Ledger the CAPTURED amount (paise → ₹) so balance payments
                    // on credit orders record correctly; idempotent per payment id
                    // (browser callback + webhook for the same payment record once).
                    $amount = isset($entity['amount']) ? ((int) $entity['amount']) / 100 : $order->balance();
                    $billing->recordPayment($order, $amount, [
                        'gateway' => 'razorpay',
                        'payment_id' => $entity['id'] ?? null,
                    ]);
                }
            }
            $event->update(['processed' => true]);
        } catch (\Throwable $e) {
            $event->update(['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    public function stripe(Request $request, StripeService $stripe, BillingService $billing)
    {
        $raw = $request->getContent();
        $signature = $request->header('Stripe-Signature', '');

        if (! $stripe->verifyWebhookSignature($raw, $signature)) {
            return response()->json(['error' => 'bad signature'], 400);
        }

        $payload = $request->json()->all();
        $event = WebhookEvent::create([
            'gateway' => 'stripe',
            'event_type' => $payload['type'] ?? 'unknown',
            'event_id' => $payload['id'] ?? null,
            'payload' => $payload,
        ]);

        try {
            if (($payload['type'] ?? '') === 'checkout.session.completed') {
                $session = $payload['data']['object'] ?? [];
                $order = Order::where('number', $session['metadata']['order_number'] ?? '')
                    ->orWhere('gateway_order_id', $session['id'] ?? '')->first();
                if ($order) {
                    $billing->markPaid($order, [
                        'gateway' => 'stripe',
                        'payment_id' => $session['payment_intent'] ?? null,
                    ]);
                }
            }
            $event->update(['processed' => true]);
        } catch (\Throwable $e) {
            $event->update(['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
