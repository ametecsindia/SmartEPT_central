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
            'plan_code' => ['required', 'exists:plans,code'],
            'devices' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:subscription,perpetual'],
            'billing' => ['required', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['required', 'in:client_hosted,cloud'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $plan = Plan::where('code', $data['plan_code'])->firstOrFail();

        $quote = $data['kind'] === 'perpetual'
            ? $this->pricing->perpetualQuote($tenant, $plan, $data['devices'])
            : $this->pricing->subscriptionQuote($tenant, $plan, $data['devices'], $data['billing'], $data['deployment']);

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

        $page = $q->latest()->paginate(25);
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
            'plan_code' => ['required', 'exists:plans,code'],
            'devices' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:subscription,perpetual'],
            'billing' => ['required', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['required', 'in:client_hosted,cloud'],
            'as_quote' => ['boolean'],
            'requested_by' => ['nullable', 'string', 'max:190'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $order = $this->billing->createOrder(
            Tenant::findOrFail($data['tenant_id']),
            Plan::where('code', $data['plan_code'])->firstOrFail(),
            $data['devices'],
            $data
        );

        AuditLog::write($order->status === 'quote' ? 'quote.created' : 'order.created', $order, ['total' => $order->total]);

        return response()->json($order->load('tenant:id,company_name'), 201);
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

        return response()->json($q->latest()->paginate(25));
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
}
