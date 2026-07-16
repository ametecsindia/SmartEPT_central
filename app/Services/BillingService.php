<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\IndianStates;
use Illuminate\Support\Facades\DB;

/**
 * Order → payment → licence → invoice automation.
 * The golden path: markPaid() is the ONLY place a payment becomes a licence,
 * whether it arrived via Razorpay webhook, Stripe webhook or manual entry.
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

    /**
     * Financial-year + month series (Ejaz's SmartPRS pattern):
     * EPT-2026-27-07-0001 — count resets every month.
     */
    private function fyMonthNumber(string $prefixKey, string $prefixDefault, string $seqPrefix): string
    {
        $now = now();
        $fyStart = $now->month >= 4 ? $now->year : $now->year - 1;
        $fy = $fyStart . '-' . substr((string) ($fyStart + 1), 2);
        $month = $now->format('m');
        $key = $seqPrefix . '_' . $fy . '_' . $month;
        $seq = (int) Setting::get($key, 0) + 1;
        Setting::set($key, $seq);

        return sprintf('%s-%s-%s-%04d', Setting::get($prefixKey, $prefixDefault), $fy, $month, $seq);
    }

    public function nextInvoiceNumber(): string
    {
        return $this->fyMonthNumber('invoice_prefix', 'EPT', 'invoice_seq');
    }

    public function nextQuoteNumber(): string
    {
        return $this->fyMonthNumber('quote_prefix', 'EPT-Q', 'quote_seq');
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

        // R3-7: coupon — a visible negative line item BEFORE GST, redeemed on payment.
        $couponMeta = [];
        if (! empty($opts['coupon_code'])) {
            [$coupon] = \App\Models\Coupon::check($opts['coupon_code'], $devices);
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
     * same device count, same billing period. markPaid() sees licence_id set
     * and RENEWS instead of issuing a new licence.
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

    /**
     * THE golden automation. Idempotent — a second call is a no-op.
     */
    public function markPaid(Order $order, array $paymentInfo = []): Order
    {
        if ($order->status === 'paid') {
            return $order;
        }

        $paid = DB::transaction(function () use ($order, $paymentInfo) {
            $order->fill([
                'status' => 'paid',
                'paid_at' => now(),
                'gateway' => $paymentInfo['gateway'] ?? $order->gateway,
                'gateway_payment_id' => $paymentInfo['payment_id'] ?? $order->gateway_payment_id,
                'manual_method' => $paymentInfo['manual_method'] ?? $order->manual_method,
                'manual_reference' => $paymentInfo['manual_reference'] ?? $order->manual_reference,
                'recorded_by' => $paymentInfo['recorded_by'] ?? $order->recorded_by,
            ])->save();

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

            // 3. Coupon redemption — counted only on real money (R3-7).
            if (! empty($meta['coupon_code'])) {
                \App\Models\Coupon::where('code', $meta['coupon_code'])->increment('used_count');
            }

            // 4. GST invoice.
            $this->createInvoice($order);

            AuditLog::write('order.paid', $order, [
                'total' => $order->total, 'gateway' => $order->gateway,
            ]);

            return $order->fresh();
        });

        // Receipt email AFTER the commit — a mailer hiccup must never roll back
        // a real payment, and MailService itself never throws.
        $this->sendPaymentReceipt($paid);

        return $paid;
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
            . "We have received your payment of {$symbol}" . number_format((float) $order->total, 2)
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

    public function createInvoice(Order $order): Invoice
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
            'status' => 'paid',
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
