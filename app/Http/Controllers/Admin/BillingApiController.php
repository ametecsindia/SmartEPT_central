<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CheckoutController;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Plan;
use App\Models\StorageUsage;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\BillingService;
use App\Services\PricingService;
use Illuminate\Http\Request;

class BillingApiController extends Controller
{
    public function __construct(
        private BillingService $billing,
        private PricingService $pricing,
    ) {
    }

    // ---------- Quotes & orders ----------

    public function quote(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'plan_code' => ['nullable', 'exists:plans,code'],
            'devices' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:subscription,perpetual'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['nullable', 'in:client_hosted,cloud'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // 13-Aug-2026: operator-entered custom price — overrides the band/
            // tier calculation for ANY user count (billing-manage roles only,
            // which POST /quote already enforces via the permissions matrix).
            'custom_price' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'include_setup' => ['nullable', 'boolean'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $plan = Plan::where('code', $data['plan_code'] ?? 'smartept')->firstOrFail();

        $customPrice = (int) ($data['custom_price'] ?? 0);
        $includeSetup = (bool) ($data['include_setup'] ?? true);
        $quote = $customPrice > 0
            ? $this->pricing->customPriceQuote($tenant, $plan, (int) $data['devices'], $data['kind'],
                $data['billing'] ?? 'annual', $customPrice, $includeSetup)
            : ($data['kind'] === 'perpetual'
                ? $this->pricing->perpetualQuote($tenant, $plan, $data['devices'])
                : $this->pricing->subscriptionQuote($tenant, $plan, $data['devices'], $data['billing'] ?? 'annual', null, $includeSetup));

        // Progressive lifetime pricing: below the configured minimum = validation.
        // (A custom price bypasses both guards — the operator's figure decides.)
        if ($customPrice <= 0 && ! empty($quote['below_min'])) {
            return response()->json(['message' => sprintf('Minimum On-Premise licence capacity is %d users.',
                (int) ($quote['min_users'] ?? 1))], 422);
        }

        // Above the last priced milestone → custom quotation (never ₹0).
        if ($customPrice <= 0 && ! empty($quote['custom'])) {
            return response()->json(['custom' => true,
                'message' => 'For more than ' . number_format((int) ($quote['max_priced_users'] ?? 0)) . ' users, enter a Custom price below (or request one).'], 200);
        }

        // Coupon preview — same maths as the order (negative line before GST).
        $couponInfo = null;
        if (! empty($data['coupon_code'])) {
            [$coupon, $reason] = \App\Models\Coupon::check($data['coupon_code'], (int) $data['devices'], $tenant->email);
            if ($coupon && ($discount = $coupon->discountFor($quote['subtotal'])) > 0) {
                $quote['lines'][] = ['type' => 'discount', 'description' => 'Discount — coupon ' . $coupon->code,
                    'qty' => 1, 'unit' => -$discount, 'amount' => -$discount];
                $quote['subtotal'] = round($quote['subtotal'] - $discount, 2);
                $couponInfo = ['ok' => true, 'code' => $coupon->code, 'discount' => $discount];
            } else {
                $couponInfo = ['ok' => false, 'reason' => $reason ?: 'not_applicable'];
            }
        }

        $gstRate = $tenant->currency === 'INR' ? (float) \App\Models\Setting::get('gst_rate', 18) : 0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return response()->json($quote + [
            'gst_rate' => $gstRate,
            'tax' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $tenant->currency,
            'coupon' => $couponInfo,
        ]);
    }

    public function orders(Request $request)
    {
        $q = Order::with(['tenant:id,company_name', 'invoice:id,order_id,number', 'licence:id,key,status,kind'])
            ->withSum('payments as received', 'amount');

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($g = $request->query('gateway')) {
            $q->where('gateway', $g);
        }
        // 7-Aug: search + sort for every role that can view Orders.
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%$search%")
                ->orWhere('quote_number', 'like', "%$search%")
                ->orWhere('description', 'like', "%$search%")
                ->orWhereHas('tenant', fn ($t) => $t->where('company_name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")));
        }
        match ($request->query('sort')) {
            'oldest' => $q->oldest(),
            'total_desc' => $q->orderByDesc('total'),
            'total_asc' => $q->orderBy('total'),
            default => $q->latest(),
        };

        $page = $q->paginate(25);
        $page->getCollection()->transform(function ($o) {
            $o->setAttribute('received', round((float) ($o->received ?? 0), 2));
            $o->setAttribute('balance', round(max(0, (float) $o->total - (float) ($o->received ?? 0)), 2));

            return $o;
        });

        return response()->json($page);
    }

    /**
     * Master prompt §10: "Credit clients — balance outstanding". Every order
     * that was provisioned before full payment, with total / received /
     * balance / payable-by. Overdue is a display concern (turns red) —
     * follow-up is MANUAL ONLY, nothing locks automatically (Ejaz, 16-Jul).
     */
    public function creditClients()
    {
        $rows = Order::with(['tenant:id,company_name,email,phone', 'invoice:id,order_id,number'])
            ->withSum('payments as received', 'amount')
            ->whereNotNull('provisioned_at')
            ->where('status', '!=', 'paid')
            ->orderByRaw('credit_due_date IS NULL, credit_due_date')
            ->get()
            ->map(function ($o) {
                $received = round((float) ($o->received ?? 0), 2);

                return [
                    'id' => $o->id,
                    'number' => $o->number,
                    'quote_number' => $o->quote_number,
                    'tenant' => $o->tenant?->only(['id', 'company_name', 'email', 'phone']),
                    'description' => $o->description,
                    'total' => (float) $o->total,
                    'received' => $received,
                    'balance' => round(max(0, (float) $o->total - $received), 2),
                    'credit_due_date' => optional($o->credit_due_date)->toDateString(),
                    'overdue' => $o->credit_due_date && $o->credit_due_date->isPast(),
                    'invoice_number' => $o->invoice?->number,
                    'currency' => $o->currency,
                    'pay_url' => url('/pay/' . $o->number . '/' . CheckoutController::token($o)),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'plan_code' => ['nullable', 'exists:plans,code'],
            'devices' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:subscription,perpetual'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['nullable', 'in:client_hosted,cloud'],
            'as_quote' => ['boolean'],
            'requested_by' => ['nullable', 'string', 'max:190'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            // 13-Aug-2026: custom price — overrides the band calculation for any
            // user count. Whoever may create orders may enter it (Ejaz's call).
            'custom_price' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);

        $plan = Plan::where('code', $data['plan_code'] ?? 'smartept')->firstOrFail();
        $customPrice = (int) ($data['custom_price'] ?? 0);
        if ($data['kind'] === 'perpetual' && $customPrice <= 0) {
            $calc = $this->pricing->calculateLifetimeLicencePrice($plan, (int) $data['devices']);
            if ($calc['below_min']) {
                return response()->json(['message' => sprintf('Minimum On-Premise licence capacity is %d users.', (int) $calc['min_users'])], 422);
            }
            if ($calc['custom']) {
                return response()->json(['custom' => true,
                    'message' => 'For more than ' . number_format((int) $calc['max_priced_users']) . ' users, enter a Custom price — or use the request queue.'], 422);
            }
        }
        $data['plan_code'] = $plan->code;

        $order = $this->billing->createOrder(
            Tenant::findOrFail($data['tenant_id']),
            $plan,
            $data['devices'],
            $data
        );

        AuditLog::write($order->status === 'quote' ? 'quote.created' : 'order.created', $order,
            ['total' => $order->total] + ($customPrice > 0 ? ['custom_price' => $customPrice] : []));

        return response()->json($order->load('tenant:id,company_name'), 201);
    }

    /**
     * Phase 3 (6-Aug-2026): ONE-SCREEN quote/order for a NEW prospect — no
     * pre-created client needed (the SmartPRS pattern). Creates the prospect
     * tenant (status 'pending') and the quotation/order together; the tenant
     * activates automatically when the pay link is paid, and the portal owner
     * account is auto-created on provisioning.
     */
    public function prospectQuote(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'state_code' => ['required', 'string', 'size:2',
                'in:' . implode(',', array_keys(\App\Support\IndianStates::MAP))],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/i'],
            'currency' => ['nullable', 'in:INR,USD'],
            'kind' => ['required', 'in:subscription,perpetual'],
            'devices' => ['required', 'integer', 'min:1', 'max:100000'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly'],
            'as_quote' => ['boolean'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'include_setup' => ['nullable', 'boolean'],
            'send_email' => ['nullable', 'boolean'],
            // 13-Aug-2026: custom price for a brand-new prospect too.
            'custom_price' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);

        if (\App\Models\Tenant::where('email', $data['email'])->exists()
            || \App\Models\TenantUser::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'A client with this email already exists — use "+ New Order / Quote" and pick them from the client list instead.'], 422);
        }

        $plan = Plan::where('code', 'smartept')->firstOrFail();
        $customPrice = (int) ($data['custom_price'] ?? 0);
        if ($data['kind'] === 'perpetual' && $customPrice <= 0) {
            $calc = $this->pricing->calculateLifetimeLicencePrice($plan, (int) $data['devices']);
            if ($calc['below_min']) {
                return response()->json(['error' => sprintf('Minimum On-Premise licence capacity is %d users.', (int) $calc['min_users'])], 422);
            }
            if ($calc['custom']) {
                return response()->json(['error' => 'For more than ' . number_format((int) $calc['max_priced_users']) . ' users enter a Custom price, or capture it as a request.'], 422);
            }
        }

        $tenant = \App\Models\Tenant::create([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'deployment' => $data['kind'] === 'perpetual' ? 'client_hosted' : 'cloud',
            'status' => 'pending',
            'currency' => $data['currency'] ?? 'INR',
            'state_code' => $data['state_code'],
            'gstin' => ! empty($data['gstin']) ? strtoupper($data['gstin']) : null,
        ]);

        $order = $this->billing->createOrder($tenant, $plan, (int) $data['devices'], [
            'kind' => $data['kind'],
            'billing' => $data['billing'] ?? 'annual',
            'as_quote' => (bool) ($data['as_quote'] ?? true),
            'include_setup' => (bool) ($data['include_setup'] ?? true),
            'coupon_code' => $data['coupon_code'] ?? null,
            'coupon_email' => $data['email'],
            'requested_by' => auth('admin')->user()->name,
            'custom_price' => $customPrice > 0 ? $customPrice : null,
        ]);

        AuditLog::write('quote.prospect_created', $order, [
            'company' => $data['company_name'], 'total' => $order->total,
        ] + ($customPrice > 0 ? ['custom_price' => $customPrice] : []));

        $payUrl = url('/pay/' . $order->number . '/' . CheckoutController::token($order));
        $printUrl = url('/pay/' . $order->number . '/' . CheckoutController::token($order) . '/quote');

        // Optionally email the prospect straight away (default yes).
        if ($data['send_email'] ?? true) {
            $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';
            app(\App\Services\MailService::class)->send(
                $data['email'],
                'SmartEPT — ' . ($order->quote_number ? 'Quotation ' . $order->quote_number : 'Order ' . $order->number) . ' for ' . $data['company_name'],
                "Hello {$data['contact_name']},\n\n"
                . "Thank you for your interest in SmartEPT. As discussed, here is your "
                . ($order->quote_number ? 'quotation' : 'order') . ":\n\n"
                . ($order->quote_number ? "Quotation no. : {$order->quote_number}\n" : "Order no.     : {$order->number}\n")
                . "{$order->description}\n"
                . 'Total payable : ' . $symbol . number_format((float) $order->total, 2) . "\n\n"
                . "View / print:\n{$printUrl}\n\n"
                . "Approve and pay securely here — activation is instant:\n{$payUrl}"
                . \App\Services\MailService::signature()
            );
        }

        return response()->json([
            'order' => $order->load('tenant:id,company_name,email'),
            'pay_url' => $payUrl,
            'print_url' => $printUrl,
        ], 201);
    }

    /**
     * Raise a standalone Installation & Onboarding invoice for a client who did not
     * buy setup up front and later needs Ametecs to install/onboard. Returns a pay
     * link the admin sends to the client. Once paid, setup_fee_paid flips so future
     * subscription orders don't re-charge it.
     */
    public function raiseSetupInvoice(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'devices' => ['required', 'integer', 'min:1', 'max:100000'],
            'as_quote' => ['boolean'],
            'requested_by' => ['nullable', 'string', 'max:190'],
            'po_number' => ['nullable', 'string', 'max:60'],
        ]);

        $order = $this->billing->createSetupOrder(
            Tenant::findOrFail($data['tenant_id']),
            $data['devices'],
            $data + ['requested_by' => $data['requested_by'] ?? auth('admin')->user()->name],
        );

        AuditLog::write('setup.invoice.raised', $order, ['total' => $order->total, 'devices' => $data['devices']]);

        return response()->json([
            'order' => $order->load('tenant:id,company_name,email'),
            'pay_url' => url('/pay/' . $order->number . '/' . CheckoutController::token($order)),
        ], 201);
    }

    // ---------- Custom-quotation REQUEST queue (Ejaz, 13-Aug-2026) ----------

    /**
     * One order/quote/request with the client's full profile — the console's
     * request modal (and any detail view) reads this instead of round-tripping
     * the list payload.
     */
    public function showOrder(Order $order)
    {
        $order->load(['tenant', 'invoice:id,order_id,number', 'licence:id,key,status,kind']);
        $order->setAttribute('received', $order->received());
        $order->setAttribute('balance', $order->balance());

        return response()->json($order);
    }

    /**
     * Edit a pending REQUEST's details (users, billing contact, notes — and the
     * client profile too when the tenant is still a 'pending' prospect born of
     * this request; an established client's profile is edited on the Clients
     * screen only). Requests carry no number and no money, so they are freely
     * editable; once converted to a numbered quotation the 11-Aug rule applies
     * again — no edit, delete + re-create.
     */
    public function updateRequest(Request $request, Order $order)
    {
        if ($order->status !== 'request') {
            return response()->json(['error' => 'Only a pending request can be edited — this row is already a ' . $order->status . '. (Numbered quotations are never edited: delete + re-create.)'], 422);
        }

        $data = $request->validate([
            'devices' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'kind' => ['nullable', 'in:subscription,perpetual'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly'],
            'billing_contact' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Client profile — applied only while the tenant is a pending prospect.
            'company_name' => ['nullable', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'state_code' => ['nullable', 'string', 'size:2',
                'in:' . implode(',', array_keys(\App\Support\IndianStates::MAP))],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/i'],
        ]);

        if (! empty($data['gstin']) && ! empty($data['state_code'])
            && substr(strtoupper($data['gstin']), 0, 2) !== $data['state_code']) {
            return response()->json(['error' => 'The GSTIN starts with "' . substr(strtoupper($data['gstin']), 0, 2)
                . '" but the state is ' . $data['state_code'] . ' — the first two digits of a GSTIN are always the state code.'], 422);
        }

        $tenant = $order->tenant;
        if ($tenant && $tenant->status === 'pending') {
            $profile = array_filter([
                'company_name' => $data['company_name'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'state_code' => $data['state_code'] ?? null,
                'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($profile) {
                $tenant->update($profile);
            }
        }

        $meta = $order->meta ?? [];
        foreach (['kind', 'billing', 'billing_contact', 'notes'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] !== null) {
                $meta[$k] = $data[$k];
            }
        }
        if (! empty($data['devices'])) {
            $meta['devices'] = (int) $data['devices'];
        }

        $order->update([
            'meta' => $meta,
            'requested_by' => $data['contact_name'] ?? $order->requested_by,
            'description' => sprintf('Custom quotation request — %d users (%s)',
                (int) ($meta['devices'] ?? 1),
                ($meta['kind'] ?? 'perpetual') === 'perpetual' ? 'On-Premise lifetime' : 'Cloud'),
        ]);

        AuditLog::write('request.updated', $order, ['devices' => $meta['devices'] ?? null]);

        return response()->json($order->fresh()->load('tenant'));
    }

    /**
     * REQUEST → numbered quotation (or directly a payable order), in place:
     * the operator enters the price (custom, or leave blank to use the band
     * calculation where it exists), the row keeps its order number and its
     * "By client / By admin" source badge, and the standard quotation email
     * with print + pay links goes out. Everything after this is the existing
     * golden path untouched.
     */
    public function convertRequest(Request $request, Order $order)
    {
        $data = $request->validate([
            'custom_price' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
            'devices' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'as_quote' => ['nullable', 'boolean'],
            'include_setup' => ['nullable', 'boolean'],
            'send_email' => ['nullable', 'boolean'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $plan = Plan::where('code', 'smartept')->firstOrFail();

        try {
            $order = $this->billing->convertRequest($order, $plan, [
                'custom_price' => (int) ($data['custom_price'] ?? 0) > 0 ? (int) $data['custom_price'] : null,
                'devices' => $data['devices'] ?? null,
                'as_quote' => (bool) ($data['as_quote'] ?? true),
                'include_setup' => (bool) ($data['include_setup'] ?? true),
                'coupon_code' => $data['coupon_code'] ?? null,
                'coupon_email' => $order->tenant?->email,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLog::write('request.converted', $order, [
            'total' => $order->total,
            'custom_price' => $order->meta['custom_price'] ?? null,
            'quote_number' => $order->quote_number,
        ]);

        $payUrl = url('/pay/' . $order->number . '/' . CheckoutController::token($order));
        $printUrl = $payUrl . '/quote';
        $tenant = $order->tenant;

        if (($data['send_email'] ?? true) && $tenant?->email) {
            $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';
            app(\App\Services\MailService::class)->send(
                $tenant->email,
                'SmartEPT — ' . ($order->quote_number ? 'Quotation ' . $order->quote_number : 'Order ' . $order->number) . ' for ' . $tenant->company_name,
                'Hello ' . ($tenant->contact_name ?: $tenant->company_name) . ",\n\n"
                . "Thank you for your custom-pricing request. Your "
                . ($order->quote_number ? 'quotation' : 'order') . " is ready:\n\n"
                . ($order->quote_number ? "Quotation no. : {$order->quote_number}\n" : "Order no.     : {$order->number}\n")
                . "{$order->description}\n"
                . 'Total payable : ' . $symbol . number_format((float) $order->total, 2) . "\n\n"
                . "View / print:\n{$printUrl}\n\n"
                . "Approve and pay securely here — activation is instant:\n{$payUrl}"
                . \App\Services\MailService::signature()
            );
        }

        return response()->json([
            'order' => $order->load('tenant:id,company_name,email'),
            'pay_url' => $payUrl,
            'print_url' => $printUrl,
        ]);
    }

    /**
     * Management approval: quotation → payable order (quote number kept).
     */
    public function approveQuote(Order $order)
    {
        if ($order->status !== 'quote') {
            return response()->json(['error' => 'Not a quotation'], 422);
        }

        $order = $this->billing->approveQuote($order);
        AuditLog::write('quote.approved', $order, ['quote_number' => $order->quote_number]);

        return response()->json($order);
    }

    /**
     * Manual/offline payment (master prompt §10, the rev186 lesson):
     * Paid (full amount received offline), Partial (part now, balance on
     * credit) or Due (whole amount on credit). Any of the three provisions the
     * workspace IMMEDIATELY — credit is a commercial judgement, the client
     * does not wait for the last rupee. Backward compatible: no payment_status
     * means the old full "mark paid" behaviour.
     */
    public function markPaid(Request $request, Order $order)
    {
        $data = $request->validate([
            'payment_status' => ['nullable', 'in:paid,partial,due'],
            'amount' => ['required_if:payment_status,partial', 'nullable', 'numeric', 'min:0.01'],
            'manual_method' => ['required_unless:payment_status,due', 'nullable', 'in:NEFT,UPI,cheque,cash,other'],
            'manual_reference' => ['nullable', 'string', 'max:190'],
            'credit_due_date' => ['required_if:payment_status,partial,due', 'nullable', 'date', 'after_or_equal:today'],
        ]);

        if ($order->status === 'paid') {
            return response()->json(['error' => 'Order already paid'], 422);
        }

        $status = $data['payment_status'] ?? 'paid';

        if ($status === 'partial' && (float) $data['amount'] >= $order->balance()) {
            return response()->json(['error' => 'That amount covers the full balance — choose "Paid in full" instead, or enter a smaller amount.'], 422);
        }

        $order = $this->billing->recordManualPayment($order, $data + [
            'payment_status' => $status,
            'recorded_by' => auth('admin')->id(),
        ]);

        return response()->json($order->load('invoice'));
    }

    /**
     * Record a later credit instalment against a provisioned order
     * ("Record balance"). The receipt goes out automatically at zero.
     */
    public function recordBalance(Request $request, Order $order)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'manual_method' => ['required', 'in:NEFT,UPI,cheque,cash,other'],
            'manual_reference' => ['nullable', 'string', 'max:190'],
        ]);

        if ($order->status === 'paid') {
            return response()->json(['error' => 'Order already fully paid'], 422);
        }
        if ((float) $data['amount'] > $order->balance() + 0.01) {
            return response()->json(['error' => 'Amount exceeds the outstanding balance of ' . number_format($order->balance(), 2) . '.'], 422);
        }

        $order = $this->billing->recordPayment($order, (float) $data['amount'], [
            'gateway' => 'manual',
            'manual_method' => $data['manual_method'],
            'manual_reference' => $data['manual_reference'] ?? null,
            'recorded_by' => auth('admin')->id(),
        ]);

        return response()->json([
            'order' => $order->load('invoice'),
            'received' => $order->received(),
            'balance' => $order->balance(),
            'settled' => $order->status === 'paid',
        ]);
    }

    /** Refund / credit note on an order (1.0 D5). Records a negative ledger row
     *  and returns the printable credit-note URL. Cannot exceed net received. */
    public function refund(Request $request, Order $order)
    {
        $received = $order->received();
        if ($received <= 0) {
            return response()->json(['error' => 'Nothing has been received on this order to refund.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . $received],
            'method' => ['nullable', 'in:NEFT,UPI,cheque,cash,other,gateway'],
            'reference' => ['nullable', 'string', 'max:190'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $payment = $this->billing->recordRefund($order, (float) $data['amount'], [
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'reason' => $data['reason'],
                'recorded_by' => auth('admin')->id(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $order = $order->fresh();

        return response()->json([
            'ok' => true,
            'credit_note_number' => $payment->credit_note_number,
            'print_url' => '/admin/credit-notes/' . $payment->id . '/print',
            'received' => $order->received(),
            'balance' => $order->balance(),
        ]);
    }

    // ---------- Invoices ----------

    public function invoices(Request $request)
    {
        $q = Invoice::with('tenant:id,company_name');

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        // 7-Aug: search + sort for every role that can view Invoices.
        if ($search = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('number', 'like', "%$search%")
                ->orWhereHas('tenant', fn ($t) => $t->where('company_name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%")));
        }
        match ($request->query('sort')) {
            'oldest' => $q->oldest(),
            'total_desc' => $q->orderByDesc('total'),
            'total_asc' => $q->orderBy('total'),
            default => $q->latest(),
        };

        return response()->json($q->paginate(25));
    }

    // ---------- Trials ----------

    public function trials()
    {
        return response()->json([
            'active' => Tenant::where('status', 'trial')->where('trial_ends_at', '>', now())
                ->orderBy('trial_ends_at')->get(),
            'expired' => Tenant::where('status', 'trial')->where('trial_ends_at', '<=', now())->get(),
        ]);
    }

    public function extendTrial(Request $request, Tenant $tenant)
    {
        $days = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:30']])['days'];

        $tenant->update([
            'trial_ends_at' => ($tenant->trial_ends_at ?? now())->addDays($days),
            'purge_after' => ($tenant->trial_ends_at ?? now())->addDays($days + 7),
        ]);
        $tenant->licences()->where('kind', 'trial')->update([
            'expires_at' => $tenant->fresh()->trial_ends_at->toDateString(),
            'status' => 'active',
        ]);

        AuditLog::write('trial.extended', $tenant, ['days' => $days]);

        return response()->json($tenant->fresh());
    }

    // ---------- Storage metering ----------

    public function storage(Request $request)
    {
        $month = $request->query('month', now()->format('Y-m'));
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        $tenants = Tenant::where('deployment', 'cloud')->get()->map(function ($t) use ($start, $end) {
            $avg = (float) StorageUsage::where('tenant_id', $t->id)
                ->whereBetween('date', [$start, $end])->avg('gb_used');
            $billableGb = $avg > 0 ? (int) ceil(max($avg, PricingService::config()['storage_min_gb'])) : 0;

            return [
                'tenant_id' => $t->id,
                'company_name' => $t->company_name,
                'avg_gb' => round($avg, 2),
                'billable_gb' => $billableGb,
                'monthly_charge' => $avg > 0 ? round($this->pricing->storageMonthly($avg), 2) : 0,
            ];
        });

        return response()->json(['month' => $month, 'tenants' => $tenants]);
    }

    public function recordStorage(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'date' => ['required', 'date'],
            'gb_used' => ['required', 'numeric', 'min:0'],
        ]);

        $row = StorageUsage::updateOrCreate(
            ['tenant_id' => $data['tenant_id'], 'date' => $data['date']],
            ['gb_used' => $data['gb_used']]
        );

        return response()->json($row, 201);
    }

    // ---------- Webhook log ----------

    public function webhooks()
    {
        return response()->json(WebhookEvent::latest()->paginate(25));
    }

    // ---------- Garbage cleanup (Ejaz, 11-Aug-2026) ----------

    /**
     * Hard-delete a QUOTE / unpaid order — super only (route group).
     * Allowed ONLY when not a single rupee is on the payments ledger and no
     * licence hangs off the order. Its (unpaid) invoice, if any, goes with it.
     * Money-bearing orders can never be deleted — refund / credit note instead.
     */
    public function deleteOrder(Order $order)
    {
        $received = $order->received();
        if ($received > 0) {
            return response()->json(['error' => 'This order has ' . number_format($received, 2)
                . ' recorded on the payments ledger — money-bearing orders can never be deleted. Use refund / credit note instead.'], 422);
        }
        if ($order->licence_id) {
            return response()->json(['error' => 'This order is linked to licence '
                . ($order->licence?->key ?? '#' . $order->licence_id) . ' — deletion blocked.'], 422);
        }

        $meta = ['number' => $order->number, 'quote_number' => $order->quote_number,
            'total' => (float) $order->total, 'tenant_id' => $order->tenant_id,
            'invoice' => $order->invoice?->number, 'description' => $order->description];

        \Illuminate\Support\Facades\DB::transaction(function () use ($order) {
            $order->invoice?->delete();   // unpaid invoice of an unpaid order
            $order->payments()->delete(); // zero rows — belt & braces
            $order->delete();
        });
        AuditLog::write('order.deleted', null, $meta);

        return response()->json(['ok' => true]);
    }

    /**
     * Hard-delete an invoice — super only (route group). Pre-launch decision
     * (Ejaz, 11-Aug-2026): any invoice may be deleted for test-data cleanup;
     * the UI shows a strong warning first, because FY-consecutive invoice
     * numbers are never reused — deleting mid-series leaves a permanent gap.
     */
    public function deleteInvoice(Invoice $invoice)
    {
        $meta = ['number' => $invoice->number, 'total' => (float) $invoice->total,
            'tenant_id' => $invoice->tenant_id, 'order_id' => $invoice->order_id,
            'status' => $invoice->status];

        $invoice->delete();
        AuditLog::write('invoice.deleted', null, $meta);

        return response()->json(['ok' => true]);
    }
}
