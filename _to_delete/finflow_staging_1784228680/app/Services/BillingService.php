<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\IndianStates;
use Illuminate\Support\Facades\DB;

/**
 * Order → payment → licence → invoice automation.
 *
 * The golden path (upgraded per the B2B Financial Flow master prompt, 16-Jul):
 * recordPayment() is the ONE ledger door — Razorpay/Stripe captures, NEFT/UPI/
 * cheque entries and credit instalments all land here. Provisioning happens on
 * the FIRST recording (even a ₹0 "Due" credit entry); the order flips to `paid`
 * and the receipt goes out exactly when the ledger covers the total.
 * markPaid() remains as the full-settlement wrapper for gateway callbacks.
 */
class BillingService
{
    public function __construct(
        private LicenceService $licences,
        private PricingService $pricing,
        private MailService $mail,
    ) {
    }

    public function nextOrderNumber(): string
    {
        $seq = (int) Setting::get('order_seq', 0) + 1;
        Setting::set('order_seq', $seq);

        return sprintf('%s-%05d', Setting::get('order_prefix', 'SEPT-ORD'), $seq);
    }

    /** Indian financial year label for a date: Jul 2026 → "2026-27". */
    public static function fyLabel(?\DateTimeInterface $at = null): string
    {
        $at = $at ?: now();
        $y = (int) $at->format('Y');
        $fyStart = (int) $at->format('n') >= 4 ? $y : $y - 1;

        return $fyStart . '-' . substr((string) ($fyStart + 1), 2);
    }

    /**
     * STANDING RULE (master prompt §12, Ejaz 16-Jul): document number =
     * {PREFIX}-{FY}-{MM}-{count} where the count runs CONSECUTIVELY through
     * the Indian financial year (GST style), resets on 1 April, and is one
     * shared series across every invoice-like document. Generated as
     * MAX(existing)+1 within the FY prefix — never COUNT+1 — so deletions can
     * never duplicate; the UNIQUE index on the number column is the backstop.
     */
    public static function nextFySeriesNumber(string $table, string $column, string $prefix): string
    {
        $fy = self::fyLabel();
        $like = $prefix . '-' . $fy . '-%';

        // Last 4 chars are the count. Max computed in PHP so the same code runs
        // on MySQL (Laragon) and SQLite (tests) without dialect-specific casts.
        $max = (int) DB::table($table)
            ->where($column, 'like', $like)
            ->pluck($column)
            ->map(fn ($n) => (int) substr((string) $n, -4))
            ->max();

        return sprintf('%s-%s-%s-%04d', $prefix, $fy, now()->format('m'), $max + 1);
    }

    public function nextInvoiceNumber(): string
    {
        return self::nextFySeriesNumber('invoices', 'number', Setting::get('invoice_prefix', 'EPT'));
    }

    public function nextQuoteNumber(): string
    {
        return self::nextFySeriesNumber('orders', 'quote_number', Setting::get('quote_prefix', 'EPT-Q'));
    }

    /**
     * Create a subscription (or perpetual) order for a tenant.
     * With as_quote=true it starts life as a QUOTATION (SmartPRS pattern):
     * a manager raises it, management pays it later via the pay link.
     */
    public function createOrder(Tenant $tenant, Plan $plan, int $devices, array $opts = []): Order
    {
        $kind = $opts['kind'] ?? 'subscription';
        $billing = $opts['billing'] ?? 'annual';
        $asQuote = (bool) ($opts['as_quote'] ?? false);

        $quote = $kind === 'perpetual'
            ? $this->pricing->perpetualQuote($tenant, $plan, $devices)
            : $this->pricing->subscriptionQuote($tenant, $plan, $devices, $billing, $opts['deployment'] ?? null, $opts['include_setup'] ?? true);

        // R3-7 + master prompt §7: coupon — a visible negative line item BEFORE
        // GST, applied AFTER the advance-period discount (they stack knowingly).
        // Captured on a quotation it is LOCKED into that quote: the discount line
        // is frozen in line_items, so the pay link honours it even if the code
        // expires meanwhile. Redemption is counted only on confirmed payment.
        $couponMeta = [];
        if (! empty($opts['coupon_code'])) {
            [$coupon] = \App\Models\Coupon::check($opts['coupon_code'], $devices, $opts['coupon_email'] ?? $tenant->email);
            if ($coupon && ($discount = $coupon->discountFor($quote['subtotal'])) > 0) {
                $quote['lines'][] = [
                    'type' => 'discount',
                    'description' => 'Discount — coupon ' . $coupon->code
                        . ($coupon->type === 'percent' ? ' (' . rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.') . '% off)' : ''),
                    'qty' => 1,
                    'unit' => -$discount,
                    'amount' => -$discount,
                ];
                $quote['subtotal'] = round($quote['subtotal'] - $discount, 2);
                $couponMeta = ['coupon_code' => $coupon->code, 'coupon_discount' => $discount];
            }
        }

        $gstRate = $tenant->currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'quote_number' => $asQuote ? $this->nextQuoteNumber() : null,
            'requested_by' => $opts['requested_by'] ?? null,
            'tenant_id' => $tenant->id,
            'description' => sprintf('%s %s — %d devices (%s)', $plan->name, $kind, $devices, $billing),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $tenant->currency,
            'gateway' => 'manual',
            'status' => $asQuote ? 'quote' : 'created',
            'meta' => array_merge([
                'plan_id' => $plan->id, 'devices' => $devices,
                'kind' => $kind, 'billing' => $billing,
                'deployment' => $opts['deployment'] ?? $tenant->deployment,
            ], $couponMeta),
        ]);
    }

    /**
     * Raise a standalone Installation & Onboarding invoice for a client who did
     * NOT buy setup up front and later needs Ametecs to install/onboard. Creates
     * a setup-only order (its own pay link); never a subscription. Optionally a quote.
     */
    public function createSetupOrder(Tenant $tenant, int $devices, array $opts = []): Order
    {
        $asQuote = (bool) ($opts['as_quote'] ?? false);
        $quote = $this->pricing->setupOnlyQuote($tenant, max(1, $devices));

        $gstRate = $tenant->currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'quote_number' => $asQuote ? $this->nextQuoteNumber() : null,
            'requested_by' => $opts['requested_by'] ?? null,
            'tenant_id' => $tenant->id,
            'description' => sprintf('Installation & Onboarding — %d devices', $devices),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $tenant->currency,
            'gateway' => 'manual',
            'status' => $asQuote ? 'quote' : 'created',
            'meta' => ['kind' => 'setup', 'devices' => $devices, 'support_invoice' => true],
        ]);
    }

    /**
     * One-click renewal (client portal self-service): same licence, same plan,
     * same device count, same billing period. Renewals always bill the FULL
     * rate — coupons discount first invoices only (master prompt §7).
     */
    public function createRenewalOrder(Licence $licence): Order
    {
        $tenant = $licence->tenant;
        $plan = $licence->plan;

        $quote = $this->pricing->subscriptionQuote(
            $tenant, $plan, $licence->device_limit, $licence->billing, $licence->deployment
        );

        $gstRate = $tenant->currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'tenant_id' => $tenant->id,
            'licence_id' => $licence->id,
            'description' => sprintf('Renewal — %s plan, %d devices (%s)',
                $plan->name, $licence->device_limit, $licence->billing),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $tenant->currency,
            'gateway' => 'manual',
            'status' => 'created',
            'meta' => [
                'renewal' => true, 'plan_id' => $plan->id,
                'devices' => $licence->device_limit, 'kind' => $licence->kind,
                'billing' => $licence->billing, 'deployment' => $licence->deployment,
            ],
        ]);
    }

    /**
     * Management approval: quotation becomes a payable order (same record,
     * quote number preserved for the paper trail).
     */
    public function approveQuote(Order $order): Order
    {
        if ($order->status === 'quote') {
            $order->update(['status' => 'created']);
        }

        return $order->fresh();
    }

    // =====================================================================
    //  THE LEDGER DOOR (master prompt §10 — the rev186 lesson)
    // =====================================================================

    /**
     * Record money against an order. Everything converges here:
     * - Razorpay/Stripe capture → the charged amount
     * - Admin "Record payment" → Paid (full) / Partial (part now) amounts
     * - Admin "Record balance" → later instalments
     *
     * Provisioning happens on the FIRST recording (even ₹0 for Due-credit);
     * settlement (order `paid`, invoice `paid`, receipt email) happens exactly
     * when the ledger covers the total. Idempotent per gateway payment id AND
     * per settled order.
     */
    public function recordPayment(Order $order, float $amount, array $info = []): Order
    {
        // Idempotency #1: the same gateway payment must never record twice
        // (webhook + browser callback race for the same payment_id).
        if (! empty($info['payment_id'])
            && OrderPayment::where('gateway_payment_id', $info['payment_id'])->exists()) {
            return $order->fresh();
        }

        $settledNow = false;
        $recorded = 0.0;

        $order = DB::transaction(function () use ($order, $amount, $info, &$settledNow, &$recorded) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            $recorded = round(min(max(0, $amount), $order->balance()), 2);

            if ($recorded > 0) {
                OrderPayment::create([
                    'order_id' => $order->id,
                    'amount' => $recorded,
                    'gateway' => $info['gateway'] ?? 'manual',
                    'method' => $info['manual_method'] ?? null,
                    'reference' => $info['manual_reference'] ?? null,
                    'gateway_payment_id' => $info['payment_id'] ?? null,
                    'recorded_by' => $info['recorded_by'] ?? null,
                    'note' => $info['note'] ?? null,
                    'paid_at' => now(),
                ]);
            }

            // A quotation that receives money (or a credit promise) is
            // implicitly approved — it leaves the open-quotes list.
            if ($order->status === 'quote') {
                $order->status = 'created';
            }

            $order->fill([
                'gateway' => $info['gateway'] ?? $order->gateway,
                'gateway_payment_id' => $info['payment_id'] ?? $order->gateway_payment_id,
                'manual_method' => $info['manual_method'] ?? $order->manual_method,
                'manual_reference' => $info['manual_reference'] ?? $order->manual_reference,
                'recorded_by' => $info['recorded_by'] ?? $order->recorded_by,
            ]);
            if (! empty($info['credit_due_date'])) {
                $order->credit_due_date = $info['credit_due_date'];
            }
            $order->save();

            // Provision on the FIRST money/credit event — the client must not
            // wait for the last rupee (the whole point of the credit path).
            $this->provisionIfNeeded($order);

            // Settle when the ledger covers the total (paisa tolerance).
            if ($order->status !== 'paid' && $order->balance() <= 0.01) {
                $order->update(['status' => 'paid', 'paid_at' => now()]);
                // Direct query, not the (possibly stale-cached) relation.
                Invoice::where('order_id', $order->id)
                    ->whereIn('status', ['issued', 'draft'])->update(['status' => 'paid']);
                AuditLog::write('order.paid', $order, [
                    'total' => $order->total, 'gateway' => $order->gateway,
                ]);
                $settledNow = true;
            } elseif ($recorded > 0) {
                AuditLog::write('order.payment_recorded', $order, [
                    'amount' => $recorded, 'received' => $order->received(),
                    'balance' => $order->balance(), 'gateway' => $order->gateway,
                ]);
            }

            return $order->fresh();
        });

        // Mail AFTER the commit — a mailer hiccup must never roll back real
        // money, and MailService itself never throws.
        if ($settledNow) {
            $this->sendPaymentReceipt($order);        // full receipt, exactly once
        } elseif ($recorded > 0) {
            $this->sendPartPaymentAcknowledgement($order, $recorded);
        }

        return $order;
    }

    /**
     * Admin "Record payment" on a quote/order — the credit-provisioning door
     * (master prompt §10). $data:
     *   payment_status: paid | partial | due
     *   amount (partial only) · manual_method · manual_reference
     *   credit_due_date (partial/due) · recorded_by
     */
    public function recordManualPayment(Order $order, array $data): Order
    {
        $status = $data['payment_status'] ?? 'paid';
        $info = [
            'gateway' => 'manual',
            'manual_method' => $data['manual_method'] ?? null,
            'manual_reference' => $data['manual_reference'] ?? null,
            'credit_due_date' => $data['credit_due_date'] ?? null,
            'recorded_by' => $data['recorded_by'] ?? null,
        ];

        if ($status === 'due') {
            // Whole amount on credit: ₹0 ledger movement, but the workspace
            // provisions NOW and the invoice carries the credit due date.
            return $this->recordPayment($order, 0.0, $info);
        }

        $amount = $status === 'partial'
            ? (float) ($data['amount'] ?? 0)
            : $order->balance();

        return $this->recordPayment($order, $amount, $info);
    }

    /**
     * Full-settlement wrapper — kept as THE entry point for gateway callbacks
     * and existing callers. Idempotent: a second call is a no-op.
     */
    public function markPaid(Order $order, array $paymentInfo = []): Order
    {
        if ($order->status === 'paid') {
            return $order;
        }

        return $this->recordPayment($order, $order->balance(), $paymentInfo);
    }

    /**
     * Issue/renew the licence, activate the tenant, redeem the coupon and cut
     * the GST invoice — once per order, on the first payment/credit event.
     * Must run inside the recordPayment transaction.
     */
    private function provisionIfNeeded(Order $order): void
    {
        if ($order->provisioned_at) {
            return;
        }

        $meta = $order->meta ?? [];
        $tenant = $order->tenant;

        // 1. Issue or renew the licence.
        if ($order->licence_id) {
            $licence = $order->licence;
            ($meta['renew_amc'] ?? false)
                ? $this->licences->renewAmc($licence)
                : $this->licences->renew($licence);
        } elseif (isset($meta['plan_id'])) {
            $licence = $this->licences->issue($tenant, Plan::findOrFail($meta['plan_id']), [
                'kind' => $meta['kind'] ?? 'subscription',
                'billing' => $meta['billing'] ?? 'annual',
                'deployment' => $meta['deployment'] ?? $tenant->deployment,
                'device_limit' => $meta['devices'] ?? 10,
            ]);
            $order->update(['licence_id' => $licence->id]);
        }

        // 2. Tenant becomes active; setup fee marked consumed if it was on this order.
        $hasSetupLine = collect($order->line_items)->contains(fn ($l) => ($l['type'] ?? '') === 'setup_fee');
        $tenant->update([
            'status' => 'active',
            'setup_fee_paid' => $tenant->setup_fee_paid || $hasSetupLine,
        ]);

        // 3. Coupon redemption — counted once, on the order that provisioned.
        if (! empty($meta['coupon_code'])) {
            \App\Models\Coupon::where('code', $meta['coupon_code'])->increment('used_count');
        }

        // 4. GST invoice — `paid` only if the ledger already covers the total,
        //    otherwise `issued` (displayed as DUE) with the credit due date.
        $fullyPaid = $order->balance() <= 0.01;
        $this->createInvoice($order, $fullyPaid);
        $order->unsetRelation('invoice'); // the `if ($order->invoice)` guard cached null pre-create

        $order->update(['provisioned_at' => now()]);

        AuditLog::write('order.provisioned', $order, [
            'received' => $order->received(), 'balance' => $order->balance(),
            'credit_due_date' => optional($order->credit_due_date)->toDateString(),
        ]);
    }

    /**
     * Plain-text payment receipt: invoice number, amount, the licence key that
     * was just issued/renewed, and the portal link for the paper trail.
     */
    private function sendPaymentReceipt(Order $order): void
    {
        $tenant = $order->tenant;
        if (! $tenant || ! $tenant->email) {
            return;
        }

        $invoice = $order->invoice;
        $licence = $order->licence;
        $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';

        $body = "Hello {$tenant->company_name},\n\n"
            . "We have received your payment in full — {$symbol}" . number_format((float) $order->total, 2)
            . " against order {$order->number}. Thank you!\n\n"
            . 'Tax invoice: ' . ($invoice ? $invoice->number : 'will follow shortly') . "\n"
            . ($licence ? "Licence key: {$licence->key}"
                . ($licence->expires_at ? ' (valid till ' . $licence->expires_at->toDateString() . ')' : '') . "\n" : '')
            . ($order->manual_method ? "Payment method: {$order->manual_method}"
                . ($order->manual_reference ? " (ref {$order->manual_reference})" : '') . "\n"
                : "Payment method: {$order->gateway}"
                . ($order->gateway_payment_id ? " (ref {$order->gateway_payment_id})" : '') . "\n")
            . "\nDownload the GST invoice and manage your licence anytime in the client portal:\n"
            . url('/client')
            . MailService::signature();

        $this->mail->send(
            $tenant->email,
            'SmartEPT — payment received' . ($invoice ? ' · Invoice ' . $invoice->number : ''),
            $body
        );
    }

    /** Warm part-payment acknowledgement with the live balance and due date. */
    private function sendPartPaymentAcknowledgement(Order $order, float $amount): void
    {
        $tenant = $order->tenant;
        if (! $tenant || ! $tenant->email) {
            return;
        }

        $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';

        $this->mail->send(
            $tenant->email,
            'SmartEPT — payment received (part) · Order ' . $order->number,
            "Hello {$tenant->company_name},\n\n"
            . "Thank you — we have received {$symbol}" . number_format($amount, 2)
            . " against order {$order->number}.\n\n"
            . 'Received so far : ' . $symbol . number_format($order->received(), 2) . "\n"
            . 'Balance payable : ' . $symbol . number_format($order->balance(), 2)
            . ($order->credit_due_date ? ' (by ' . $order->credit_due_date->format('d M Y') . ')' : '') . "\n\n"
            . "Your SmartEPT licence is already active — the balance can be paid anytime\n"
            . "using the same payment link, or by NEFT/UPI (share the UTR on WhatsApp 90000 98877).\n"
            . 'The full receipt follows automatically when the balance reaches zero.'
            . MailService::signature()
        );
    }

    /**
     * Cut the GST tax invoice for an order. $paid=false leaves it `issued`
     * (displayed as DUE) with the credit due date — it flips to `paid`
     * automatically when the payments ledger covers amount+tax.
     */
    public function createInvoice(Order $order, bool $paid = true): Invoice
    {
        if ($order->invoice) {
            return $order->invoice;
        }

        $tenant = $order->tenant;
        $tax = (float) $order->tax_amount;

        // GST split — a BREAKDOWN of the same 18%, never a change to the total.
        // Intra-state (buyer state == seller state 36-Telangana): CGST 9% + SGST 9%.
        // Inter-state: IGST 18%. Buyer GSTIN + place of supply are snapshotted
        // here because a tax document must not change when the profile does.
        $cgst = $sgst = $igst = 0.0;
        $placeOfSupply = null;

        if ($order->currency === 'INR' && $tax > 0) {
            $sellerState = (string) Setting::get('seller_state_code', '36');
            // No declared buyer state = local B2C supply at the seller's place
            // of business, so it falls back to the seller state (intra-state).
            $buyerState = $tenant->state_code ?: $sellerState;
            $placeOfSupply = IndianStates::placeOfSupply($buyerState);

            if ($buyerState === $sellerState) {
                $cgst = round($tax / 2, 2);
                $sgst = round($tax - $cgst, 2); // absorbs the odd paisa so cgst+sgst == tax exactly
            } else {
                $igst = $tax;
            }
        }

        return Invoice::create([
            'number' => $this->nextInvoiceNumber(),
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'date' => now()->toDateString(),
            'due_date' => $paid ? null : optional($order->credit_due_date)->toDateString(),
            'line_items' => $order->line_items,
            'subtotal' => $order->subtotal,
            'gst_rate' => $order->currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0,
            'gst_amount' => $order->tax_amount,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'place_of_supply' => $placeOfSupply,
            'buyer_gstin' => $tenant?->gstin,
            'sac_code' => (string) Setting::get('sac_code', '997331'),
            'total' => $order->total,
            'currency' => $order->currency,
            'status' => $paid ? 'paid' : 'issued',
        ]);
    }

    /**
     * Provision a 7-day self-service trial tenant + licence (used by /client signup in Phase 3,
     * and by admins creating trials manually today).
     */
    public function provisionTrial(Tenant $tenant): Licence
    {
        $plan = Plan::where('code', 'professional')->firstOrFail();

        $tenant->update([
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(7),
            'purge_after' => now()->addDays(14),
        ]);

        return $this->licences->issue($tenant, $plan, ['kind' => 'trial', 'device_limit' => 10]);
    }
}
