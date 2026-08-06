<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BillingService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public BUY front door (Phase 1 of the money rework, 6-Aug-2026).
 *
 * SmartPRS pattern on the SmartEPT engine: the visitor picks Cloud/Perpetual,
 * users and cycle, gives company + GST details, and PAYS — the account only
 * becomes active when the money is verified. Everything downstream reuses the
 * existing golden path untouched: BillingService::createOrder() prices the
 * order server-side (browser numbers are never trusted) and the existing
 * /pay/{number}/{token} checkout + recordPayment() provisions licence,
 * invoice, receipt and WhatsApp exactly as today.
 *
 * No OTP here — payment is the proof of intent (trial keeps its OTP).
 */
class BuyController extends Controller
{
    public function show()
    {
        return view('buy');
    }

    public function order(Request $request, BillingService $billing, PricingService $pricing)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'state_code' => ['required', 'string', 'size:2',
                'in:' . implode(',', array_keys(\App\Support\IndianStates::MAP))],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/i'],
            'kind' => ['required', 'in:cloud,perpetual'],
            'users' => ['required', 'integer', 'min:1', 'max:100000'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly'],
            'include_setup' => ['nullable', 'boolean'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'terms_accepted' => ['accepted'],
        ], [
            'state_code.required' => 'Please pick your state — it decides how GST appears on your invoice (CGST+SGST for Telangana, IGST for other states).',
            'state_code.in' => 'Please pick your state from the list — it decides how GST appears on your invoice.',
            'gstin.regex' => 'That GSTIN does not look right — it is 15 characters, starting with your 2-digit state code (e.g. 36AAHCT0971F1ZB).',
            'terms_accepted.accepted' => 'Please tick the box agreeing to our Terms and Refund policy to continue.',
        ]);

        // The first two GSTIN digits ARE the state code — a mismatch would put
        // the wrong tax split on a legal document (same rule as trial signup).
        if (! empty($data['gstin']) && substr(strtoupper($data['gstin']), 0, 2) !== $data['state_code']) {
            return response()->json(['error' => 'Your GSTIN starts with "' . substr(strtoupper($data['gstin']), 0, 2)
                . '" but you picked state ' . $data['state_code'] . ' ('
                . (\App\Support\IndianStates::name($data['state_code']) ?: 'unknown')
                . '). The first two digits of a GSTIN are always the state code — please match them so your tax invoice is correct.'], 422);
        }

        if (TenantUser::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'This email already has a SmartEPT account. Please sign in to your client portal — you can buy, upgrade or renew right from there.'], 422);
        }

        $plan = Plan::where('code', 'smartept')->where('active', true)->first();
        if (! $plan) {
            return response()->json(['error' => 'Pricing is being updated right now — please try again in a few minutes, or WhatsApp us on 90000 98877.'], 422);
        }

        $kind = $data['kind'] === 'perpetual' ? 'perpetual' : 'subscription';
        if ($kind === 'perpetual' && $pricing->perpetualBandFor($plan, (int) $data['users']) === null) {
            return response()->json(['error' => 'For more than 5,000 users we prepare a custom quotation — WhatsApp 90000 98877 and we will have it ready the same day.'], 422);
        }

        [$tenant, $order] = DB::transaction(function () use ($data, $billing, $plan, $kind) {
            $tenant = Tenant::create([
                'company_name' => $data['company_name'],
                'contact_name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // v2: subscription = SmartEPT Cloud, perpetual = client-hosted.
                'deployment' => $kind === 'perpetual' ? 'client_hosted' : 'cloud',
                // Activation happens on payment (BillingService::provisionIfNeeded
                // flips this to 'active' on the first verified rupee).
                'status' => 'pending',
                'state_code' => $data['state_code'],
                'gstin' => ! empty($data['gstin']) ? strtoupper($data['gstin']) : null,
                'terms_accepted_at' => now(),
            ]);

            // The buyer chose their own password (same as trial signup), so no
            // temp-password machinery is needed — after payment they are signed
            // straight in and can also sign in manually any time to find their
            // order + pay link (abandoned-payment recovery for free).
            TenantUser::create([
                'tenant_id' => $tenant->id,
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => 'owner',
                'active' => 1,
                'must_set_password' => false,
                'email_verified_at' => null,
            ]);

            $order = $billing->createOrder($tenant, $plan, (int) $data['users'], [
                'kind' => $kind,
                'billing' => $data['billing'] ?? 'annual',
                'include_setup' => (bool) ($data['include_setup'] ?? false),
                'coupon_code' => $data['coupon_code'] ?? null,
                'coupon_email' => $data['email'],
            ]);

            // Mark the order as born on the public buy page so the checkout
            // callback knows to sign the buyer in after payment.
            $order->update(['meta' => array_merge($order->meta ?? [], ['buy_flow' => true])]);

            return [$tenant, $order];
        });

        AuditLog::write('buy.order_created', $order, [
            'total' => $order->total, 'email' => $data['email'], 'kind' => $kind, 'users' => (int) $data['users'],
        ]);

        return response()->json([
            'ok' => true,
            'number' => $order->number,
            'total' => $order->total,
            'pay_url' => url('/pay/' . $order->number . '/' . CheckoutController::token($order)),
        ], 201);
    }
}
