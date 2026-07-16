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
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);
        $plan = Plan::where('code', $data['plan_code'])->firstOrFail();

        $quote = $data['kind'] === 'perpetual'
            ? $this->pricing->perpetualQuote($tenant, $plan, $data['devices'])
            : $this->pricing->subscriptionQuote($tenant, $plan, $data['devices'], $data['billing'], $data['deployment']);

        $gstRate = $tenant->currency === 'INR' ? (float) \App\Models\Setting::get('gst_rate', 18) : 0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return response()->json($quote + [
            'gst_rate' => $gstRate,
            'tax' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $tenant->currency,
        ]);
    }

    public function orders(Request $request)
    {
        $q = Order::with(['tenant:id,company_name', 'invoice:id,order_id,number']);

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($g = $request->query('gateway')) {
            $q->where('gateway', $g);
        }

        return response()->json($q->latest()->paginate(25));
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
     * Manual/offline payment: NEFT, UPI, cheque, cash — same golden automation.
     */
    public function markPaid(Request $request, Order $order)
    {
        $data = $request->validate([
            'manual_method' => ['required', 'in:NEFT,UPI,cheque,cash,other'],
            'manual_reference' => ['nullable', 'string', 'max:190'],
        ]);

        if ($order->status === 'paid') {
            return response()->json(['error' => 'Order already paid'], 422);
        }

        $order = $this->billing->markPaid($order, $data + [
            'gateway' => 'manual',
            'recorded_by' => auth('admin')->id(),
        ]);

        return response()->json($order->load('invoice'));
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
            $billableGb = $avg > 0 ? (int) ceil(max($avg, PricingService::STORAGE_MIN_GB)) : 0;

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
