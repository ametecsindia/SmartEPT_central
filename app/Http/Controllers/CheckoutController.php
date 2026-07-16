<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\RazorpayService;
use App\Services\StripeService;
use Illuminate\Http\Request;

/**
 * Public checkout page for an order. The link is /pay/{number}/{token} where
 * token = HMAC of the order number — safe to send on WhatsApp/email.
 */
class CheckoutController extends Controller
{
    public static function token(Order $order): string
    {
        return substr(hash_hmac('sha256', 'pay:' . $order->number, config('app.key')), 0, 20);
    }

    private function findOrder(string $number, string $token): Order
    {
        $order = Order::with('tenant')->where('number', $number)->firstOrFail();
        abort_unless(hash_equals(self::token($order), $token), 403);

        return $order;
    }

    public function show(string $number, string $token, RazorpayService $razorpay, StripeService $stripe)
    {
        $order = $this->findOrder($number, $token);

        return view('checkout', [
            'order' => $order,
            'token' => $token,
            'razorpayEnabled' => $razorpay->enabled() && $order->currency === 'INR',
            'stripeEnabled' => $stripe->enabled(),
            'razorpayKeyId' => $razorpay->keyId(),
        ]);
    }

    public function createRazorpayOrder(string $number, string $token, RazorpayService $razorpay)
    {
        $order = $this->findOrder($number, $token);

        if ($order->status === 'paid') {
            return response()->json(['error' => 'Order already paid'], 422);
        }

        $result = $razorpay->createOrder($order);

        return response()->json($result, $result['ok'] ? 200 : 502);
    }

    public function razorpayCallback(Request $request, string $number, string $token,
                                     RazorpayService $razorpay, \App\Services\BillingService $billing)
    {
        $order = $this->findOrder($number, $token);

        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        if (! $razorpay->verifyPaymentSignature($data['razorpay_order_id'],
            $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            return response()->json(['error' => 'Signature verification failed'], 400);
        }

        $billing->markPaid($order, ['gateway' => 'razorpay', 'payment_id' => $data['razorpay_payment_id']]);

        return response()->json(['ok' => true, 'redirect' => "/pay/$number/$token?paid=1"]);
    }

    public function stripeRedirect(string $number, string $token, StripeService $stripe)
    {
        $order = $this->findOrder($number, $token);

        if ($order->status === 'paid') {
            return redirect("/pay/$number/$token?paid=1");
        }

        $base = url("/pay/$number/$token");
        $result = $stripe->createCheckoutSession($order, $base . '?paid=1', $base . '?cancelled=1');

        abort_unless($result['ok'], 502, $result['error'] ?? 'Stripe error');

        return redirect()->away($result['url']);
    }
}
