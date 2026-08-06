<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\BillingService;
use App\Services\RazorpayService;
use App\Services\StripeService;
use Illuminate\Http\Request;

/**
 * Public checkout page for an order. The link is /pay/{number}/{token} where
 * token = HMAC of the order number — safe to send on WhatsApp/email.
 *
 * Master prompt §10: the SAME link stays alive for credit clients — it shows
 * total / received so far / balance, and the pay button collects the BALANCE.
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
            'expired' => $order->isExpired(),
            'received' => $order->received(),
            'balance' => $order->balance(),
            'token' => $token,
            'razorpayEnabled' => $razorpay->enabled() && $order->currency === 'INR',
            'stripeEnabled' => $stripe->enabled(),
            'razorpayKeyId' => $razorpay->keyId(),
        ]);
    }

    public function createRazorpayOrder(string $number, string $token, RazorpayService $razorpay)
    {
        $order = $this->findOrder($number, $token);

        if ($order->isExpired()) {
            return response()->json(['error' => 'This quotation has expired. Please contact sales on WhatsApp 90000 98877 for a fresh quote.'], 410);
        }

        if ($order->status === 'paid' || $order->balance() <= 0.01) {
            return response()->json(['error' => 'Order already paid'], 422);
        }

        // Balance, not total — an offline instalment may have been recorded
        // since the page was opened.
        $result = $razorpay->createOrder($order, $order->balance());

        return response()->json($result, $result['ok'] ? 200 : 502);
    }

    public function razorpayCallback(Request $request, string $number, string $token,
                                     RazorpayService $razorpay, BillingService $billing)
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

        // Charged amount read back from the Razorpay order (authoritative) in
        // case an offline entry landed between order-create and completion.
        $amount = $razorpay->fetchOrderAmount($data['razorpay_order_id']) ?? $order->balance();

        $billing->recordPayment($order, $amount, [
            'gateway' => 'razorpay',
            'payment_id' => $data['razorpay_payment_id'],
        ]);

        // Phase 1 buy flow: the buyer created this order on the public /buy page
        // with their own chosen password — sign them straight into the client
        // portal once the money is verified (SmartPRS auto-sign-in pattern).
        if (($order->meta['buy_flow'] ?? false) && $order->fresh()->balance() <= 0.01) {
            $user = \App\Models\TenantUser::where('tenant_id', $order->tenant_id)
                ->where('active', 1)->orderBy('id')->first();
            if ($user && ! auth('client')->check()) {
                auth('client')->login($user, true);
                $request->session()->regenerate();
                $user->update(['last_login_at' => now(), 'email_verified_at' => $user->email_verified_at ?: now()]);
            }

            return response()->json(['ok' => true, 'redirect' => '/client?paid=1']);
        }

        return response()->json(['ok' => true, 'redirect' => "/pay/$number/$token?paid=1"]);
    }

    /**
     * Phase 3 (6-Aug-2026): PUBLIC printable quotation — same token security as
     * the pay page, so the emailed link works for finance/management without
     * any login. Reuses the existing quote-print template untouched.
     */
    public function quotePrint(string $number, string $token)
    {
        $order = $this->findOrder($number, $token);
        abort_unless($order->quote_number, 404);

        return view('quote-print', [
            'order' => $order->load('tenant'),
            'payUrl' => url('/pay/' . $order->number . '/' . $token),
            'company' => [
                'name' => \App\Models\Setting::get('company_name', 'Ametecs India Private Limited'),
                'address' => \App\Models\Setting::get('company_address', ''),
                'gstin' => \App\Models\Setting::get('company_gstin', ''),
                'phone' => \App\Models\Setting::get('company_phone', ''),
                'email' => \App\Models\Setting::get('company_email', ''),
                'state' => \App\Support\IndianStates::placeOfSupply(\App\Models\Setting::get('seller_state_code', '36')),
                'bank_account_name' => \App\Models\Setting::get('bank_account_name', ''),
                'bank_name' => \App\Models\Setting::get('bank_name', ''),
                'bank_branch' => \App\Models\Setting::get('bank_branch', ''),
                'bank_account_no' => \App\Models\Setting::get('bank_account_no', ''),
                'bank_ifsc' => \App\Models\Setting::get('bank_ifsc', ''),
                'upi_id' => \App\Models\Setting::get('upi_id', ''),
            ],
        ]);
    }

    public function stripeRedirect(string $number, string $token, StripeService $stripe)
    {
        $order = $this->findOrder($number, $token);

        if ($order->status === 'paid') {
            return redirect("/pay/$number/$token?paid=1");
        }

        if ($order->isExpired()) {
            abort(410, 'This quotation has expired. Please contact sales on WhatsApp 90000 98877 for a fresh quote.');
        }

        $base = url("/pay/$number/$token");
        $result = $stripe->createCheckoutSession($order, $base . '?paid=1', $base . '?cancelled=1');

        abort_unless($result['ok'], 502, $result['error'] ?? 'Stripe error');

        return redirect()->away($result['url']);
    }
}
