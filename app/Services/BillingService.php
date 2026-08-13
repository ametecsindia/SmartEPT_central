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
        private ProductProvisioner $console,
    ) {
    }

    public function nextOrderNumber(): string
    {
        // EPT-15: serialise the counter under a row lock so two concurrent
        // createOrder() calls can never compute the same number. Own short
        // transaction (callers aren't transactional); orders.number UNIQUE backstop.
        $seq = DB::transaction(function () {
            DB::table('settings')->insertOrIgnore(['key' => 'order_seq', 'value' => 0]);
            $row = Setting::where('key', 'order_seq')->lockForUpdate()->first();
            $seq = (int) $row->value + 1;
            $row->update(['value' => $seq]);

            return $seq;
        });

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
        $seqKey = 'seq:' . $prefix . ':' . $fy;   // per-series, per-FY → resets 1 April

        // EPT-15: monotonic locked counter. Called from provisionIfNeeded() inside
        // recordPayment's DB::transaction, so the row lock serialises concurrent
        // settlements and two can never collide. Seeds once per FY from the live
        // MAX, so a pre-counter table or a restored backup never re-issues a number
        // already on a document; the counter only increases (deletions never
        // duplicate); the UNIQUE index on the number column stays the backstop.
        $next = DB::transaction(function () use ($seqKey, $table, $column, $prefix, $fy) {
            $row = Setting::where('key', $seqKey)->lockForUpdate()->first();

            if (! $row) {
                $like = $prefix . '-' . $fy . '-%';
                $max = (int) DB::table($table)->where($column, 'like', $like)
                    ->pluck($column)->map(fn ($n) => (int) substr((string) $n, -4))->max();
                DB::table('settings')->insertOrIgnore(['key' => $seqKey, 'value' => $max]);
                $row = Setting::where('key', $seqKey)->lockForUpdate()->first();
            }

            $next = (int) $row->value + 1;
            $row->update(['value' => $next]);

            return $next;
        });

        return sprintf('%s-%s-%s-%04d', $prefix, $fy, now()->format('m'), $next);
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
        $asQuote = (bool) ($opts['as_quote'] ?? false);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'quote_number' => $asQuote ? $this->nextQuoteNumber() : null,
            'tenant_id' => $tenant->id,
            'status' => $asQuote ? 'quote' : 'created',
            'source' => $opts['source'] ?? 'admin',
        ] + $this->buildOrderAttributes($tenant, $plan, $devices, $opts));
    }

    /**
     * The shared order maths (13-Aug-2026 refactor — behaviour unchanged):
     * everything except number / quote number / status / tenant / source, so
     * createOrder() and convertRequest() price identically.
     */
    private function buildOrderAttributes(Tenant $tenant, Plan $plan, int $devices, array $opts = []): array
    {
        $kind = $opts['kind'] ?? 'subscription';
        $billing = $opts['billing'] ?? 'annual';

        // CUSTOM PRICE (Ejaz, 13-Aug-2026): an operator-entered figure replaces
        // the band/tier calculation — any user count allowed, ₹0 impossible.
        $customPrice = (isset($opts['custom_price']) && (int) $opts['custom_price'] > 0)
            ? (int) $opts['custom_price'] : null;

        $quote = $customPrice
            ? $this->pricing->customPriceQuote($tenant, $plan, $devices, $kind, $billing, $customPrice, $opts['include_setup'] ?? true)
            : ($kind === 'perpetual'
                ? $this->pricing->perpetualQuote($tenant, $plan, $devices)
                : $this->pricing->subscriptionQuote($tenant, $plan, $devices, $billing, $opts['deployment'] ?? null, $opts['include_setup'] ?? true));

        // HARD GUARD (11-Aug-2026): a perpetual count that resolves to Custom
        // Quote or below the configured minimum must NEVER become a ₹0/free
        // lifetime licence — controllers validate first, this is the last door.
        // (A custom price satisfies it by definition — its lines carry the price.)
        if ($kind === 'perpetual' && ! $customPrice
            && (! empty($quote['custom']) || ! empty($quote['below_min']) || empty($quote['lines']))) {
            throw new \RuntimeException(! empty($quote['below_min'])
                ? sprintf('Minimum On-Premise licence capacity is %d users.', (int) ($quote['min_users'] ?? 1))
                : 'This user count needs a custom quotation — an automatic lifetime price is not available for it.');
        }

        // Honour an explicit "no setup" on the band-priced perpetual path too —
        // perpetualQuote() auto-adds the fee on a first order, silently ignoring
        // include_setup=false (admin "Include setup: No" and the /buy checkbox
        // both hit this). The operator/buyer choice must win; the custom setup
        // box below still force-adds when filled.
        if (array_key_exists('include_setup', $opts) && $opts['include_setup'] === false) {
            $quote['lines'] = array_values(array_filter($quote['lines'],
                fn ($l) => ($l['type'] ?? '') !== 'setup_fee'));
            $quote['subtotal'] = round(array_sum(array_column($quote['lines'], 'amount')), 2);
        }

        // CUSTOM SETUP CHARGE (13-Aug-2026): entered ⇒ replaces the calculated
        // setup fee and forces the line in (even if setup was already paid);
        // blank ⇒ the automatic behaviour above stands. Cloud + Perpetual alike.
        $customSetup = (isset($opts['custom_setup']) && (int) $opts['custom_setup'] > 0)
            ? (int) $opts['custom_setup'] : null;
        if ($customSetup) {
            $quote = $this->pricing->applyCustomSetup($quote, $customSetup);
        }

        // CUSTOM AMC CHARGE (13-Aug-2026): entered ⇒ an AMC line is added at
        // exactly that figure; blank ⇒ no AMC line (billed separately as today).
        $customAmc = (isset($opts['custom_amc']) && (int) $opts['custom_amc'] > 0)
            ? (int) $opts['custom_amc'] : null;
        if ($customAmc) {
            $quote = $this->pricing->applyCustomAmc($quote, $customAmc);
        }

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

        // A freshly-created Tenant model may not have hydrated its DB default —
        // treat missing currency as INR so GST is never silently skipped.
        $currency = $tenant->currency ?: 'INR';
        // Phase 4 (6-Aug-2026): international orders — INR pricing converted to
        // USD at the admin-set rate; zero-GST export invoice downstream.
        $this->convertCurrency($quote, $currency);
        $gstRate = $currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return [
            'requested_by' => $opts['requested_by'] ?? null,
            'po_number' => $opts['po_number'] ?? null,
            'valid_until' => now()->addDays((int) Setting::get('quote_validity_days', 7))->toDateString(),
            'description' => ($kind === 'perpetual'
                ? sprintf('%s Perpetual — %d users (lifetime)', $plan->name, $devices)
                : sprintf('%s Cloud — %d users (%s)', $plan->name, $devices, $billing))
                . ($customPrice ? ' · special price' : ''),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $currency,
            'gateway' => 'manual',
            'meta' => array_merge([
                'plan_id' => $plan->id, 'devices' => $devices,
                'kind' => $kind, 'billing' => $billing,
                // v2: deployment is implied by kind — subscription = Cloud, perpetual = client-hosted.
                'deployment' => $kind === 'perpetual' ? 'client_hosted' : 'cloud',
            ], $customPrice ? ['custom_price' => $customPrice] : [],
               $customSetup ? ['custom_setup' => $customSetup] : [],
               $customAmc ? ['custom_amc' => $customAmc] : [], $couponMeta),
        ];
    }

    /**
     * Convert a pending custom-quotation REQUEST (status 'request' — captured
     * on public /buy or by staff) into a proper numbered quotation / payable
     * order, IN PLACE: same row, same order number, source badge preserved,
     * the request's details kept in meta for the paper trail. From here the
     * lifecycle is 100% the existing golden path (approve → pay → provision →
     * invoice → licence).
     */
    public function convertRequest(Order $order, Plan $plan, array $opts = []): Order
    {
        if ($order->status !== 'request') {
            throw new \RuntimeException('Only a pending request can be converted — this row is already a '
                . $order->status . '.');
        }

        $tenant = $order->tenant;
        $meta = $order->meta ?? [];
        $devices = (int) ($opts['devices'] ?? $meta['devices'] ?? 1);
        $asQuote = (bool) ($opts['as_quote'] ?? true);

        $opts += [
            'kind' => $meta['kind'] ?? 'perpetual',
            'billing' => $meta['billing'] ?? 'annual',
            'requested_by' => $order->requested_by,
        ];

        $attrs = $this->buildOrderAttributes($tenant, $plan, $devices, $opts);
        // Keep the request's own details (billing contact, notes, request flag)
        // underneath the freshly-priced meta.
        $attrs['meta'] = array_merge($meta, $attrs['meta'], [
            'request' => true,
            'request_converted_at' => now()->toDateTimeString(),
        ]);

        $order->update($attrs + [
            'quote_number' => $order->quote_number ?: ($asQuote ? $this->nextQuoteNumber() : null),
            'status' => $asQuote ? 'quote' : 'created',
        ]);

        return $order->fresh();
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

        // A freshly-created Tenant model may not have hydrated its DB default —
        // treat missing currency as INR so GST is never silently skipped.
        $currency = $tenant->currency ?: 'INR';
        $gstRate = $currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'quote_number' => $asQuote ? $this->nextQuoteNumber() : null,
            'requested_by' => $opts['requested_by'] ?? null,
            'po_number' => $opts['po_number'] ?? null,
            'valid_until' => now()->addDays((int) Setting::get('quote_validity_days', 7))->toDateString(),
            'tenant_id' => $tenant->id,
            'description' => sprintf('Installation & Onboarding — %d devices', $devices),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $currency,
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

        // Downgrade-at-renewal (Ejaz, 12-Aug-2026): a scheduled reduction bills
        // the REDUCED size and applies on provisioning. Mid-period reductions
        // stay impossible; growth uses the pro-rata upgrade instead.
        $devices = (int) ($licence->renewal_device_limit ?: $licence->device_limit);

        $quote = $this->pricing->subscriptionQuote(
            $tenant, $plan, $devices, $licence->billing, $licence->deployment
        );

        // GRANDFATHER (Pricing v2 finalise): a pre-v2 'legacy' licence renews onto the
        // user-based model, but its FIRST renewal is CAPPED so the bill cannot jump more
        // than pricing_grandfather_cap_pct above the ex-GST price it last paid. The cap is
        // a visible negative line before GST; after this renewal it becomes a normal v2
        // licence (the flip happens on provisioning).
        $grandfather = false;
        if ($licence->pricing_model === 'legacy' && $licence->legacy_baseline_inr) {
            $grandfather = true;
            $capPct = (float) Setting::get('pricing_grandfather_cap_pct', 20);
            $cap = round((float) $licence->legacy_baseline_inr * (1 + $capPct / 100), 2);
            if ($quote['subtotal'] > $cap + 0.01) {
                $protection = round($quote['subtotal'] - $cap, 2);
                $quote['lines'][] = [
                    'type' => 'grandfather',
                    'description' => 'Loyalty price protection (renewal capped at '
                        . rtrim(rtrim(number_format($capPct, 2), '0'), '.') . '% over your previous rate)',
                    'qty' => 1, 'unit' => -$protection, 'amount' => -$protection,
                ];
                $quote['subtotal'] = $cap;
            }
        }

        // A freshly-created Tenant model may not have hydrated its DB default —
        // treat missing currency as INR so GST is never silently skipped.
        $currency = $tenant->currency ?: 'INR';
        $this->convertCurrency($quote, $currency);
        $gstRate = $currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'tenant_id' => $tenant->id,
            'licence_id' => $licence->id,
            'valid_until' => now()->addDays((int) Setting::get('quote_validity_days', 7))->toDateString(),
            'description' => sprintf('Renewal — %s plan, %d devices (%s)%s',
                $plan->name, $devices, $licence->billing,
                $devices < $licence->device_limit
                    ? sprintf(' — scheduled reduction from %d users applies on payment', $licence->device_limit)
                    : ''),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $currency,
            'gateway' => 'manual',
            'status' => 'created',
            'meta' => [
                'renewal' => true, 'plan_id' => $plan->id, 'grandfather_migrated' => $grandfather,
                'devices' => $devices, 'kind' => $licence->kind,
                'billing' => $licence->billing, 'deployment' => $licence->deployment,
                // Set only when a reduction is scheduled — provisioning applies it.
                'apply_device_limit' => $devices !== (int) $licence->device_limit ? $devices : null,
            ],
        ]);
    }

    /**
     * Phase 4 (6-Aug-2026): convert an INR-priced quote into the tenant's
     * currency. INR is untouched (the pricing engine itself is never modified).
     * USD uses the admin-set usd_inr_rate (Settings -> Pricing & Cloud).
     */
    private function convertCurrency(array &$quote, string $currency): void
    {
        if ($currency === 'INR' || empty($quote['lines'])) {
            return;
        }

        $fx = max(1.0, (float) Setting::get('usd_inr_rate', 88));

        foreach ($quote['lines'] as &$line) {
            $line['unit'] = round(((float) $line['unit']) / $fx, 2);
            $line['amount'] = round(((float) $line['amount']) / $fx, 2);
            $line['description'] .= ' (USD)';
        }
        unset($line);

        $quote['subtotal'] = round(array_sum(array_column($quote['lines'], 'amount')), 2);
    }

    /**
     * Phase 5 (6-Aug-2026): PRO-RATA MID-PERIOD UPGRADE (SmartPRS pattern).
     * The client adds users today and pays only the per-month difference for
     * the days remaining in the current period. The expiry date does NOT move;
     * on provisioning the licence's device_limit rises to the new count, so
     * the next renewal bills the new size automatically.
     */
    public function createUpgradeOrder(Licence $licence, int $newLimit): Order
    {
        $tenant = $licence->tenant;
        $plan = $licence->plan;

        if ($licence->kind !== 'subscription' || $licence->status !== 'active') {
            throw new \RuntimeException('Only an active Cloud subscription can be upgraded mid-period.');
        }
        if (! $licence->expires_at || ! $licence->expires_at->isFuture()) {
            throw new \RuntimeException('This licence has no remaining period — use Renew instead.');
        }
        if ($newLimit <= $licence->device_limit) {
            throw new \RuntimeException('Enter a user count higher than the current ' . $licence->device_limit
                . ' — reductions apply at renewal, not mid-period.');
        }

        $eco = (bool) $tenant->ecosystem_customer;
        $rateNew = $this->pricing->deviceRate($plan, $newLimit, $licence->billing, 'cloud', $eco);
        $rateOld = $this->pricing->deviceRate($plan, $licence->device_limit, $licence->billing, 'cloud', $eco);

        $remainingDays = (int) now()->startOfDay()->diffInDays($licence->expires_at->copy()->startOfDay());
        $remainingDays = max(1, $remainingDays);
        $months = $remainingDays / 30.44;

        $diffPerMonth = round($newLimit * $rateNew - $licence->device_limit * $rateOld, 2);
        if ($diffPerMonth <= 0) {
            throw new \RuntimeException('Nothing extra to charge for that count — WhatsApp 90000 98877 and we will sort it manually.');
        }

        $amount = round($diffPerMonth * $months, 2);

        $quote = ['lines' => [[
            'type' => 'upgrade',
            'description' => sprintf('Mid-period upgrade %d → %d users — pro-rata for %d remaining day(s) (till %s)',
                $licence->device_limit, $newLimit, $remainingDays, $licence->expires_at->toDateString()),
            'qty' => 1,
            'unit' => $amount,
            'amount' => $amount,
        ]], 'subtotal' => $amount];

        $currency = $tenant->currency ?: 'INR';
        $this->convertCurrency($quote, $currency);
        $gstRate = $currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'tenant_id' => $tenant->id,
            'licence_id' => $licence->id,
            'valid_until' => now()->addDays((int) Setting::get('quote_validity_days', 7))->toDateString(),
            'description' => sprintf('Upgrade — %s plan, %d → %d users (pro-rata)', $plan->name, $licence->device_limit, $newLimit),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $currency,
            'gateway' => 'manual',
            'status' => 'created',
            'meta' => [
                'upgrade_new_limit' => $newLimit, 'plan_id' => $plan->id,
                'devices' => $newLimit, 'kind' => $licence->kind,
                'billing' => $licence->billing, 'deployment' => $licence->deployment,
            ],
        ]);
    }

    /**
     * PERPETUAL (lifetime) UPGRADE (Ejaz, 12-Aug-2026): once purchased, seats
     * only go UP — the client pays the DIFFERENCE between the progressive/
     * interpolated lifetime price at the new count and at the current count
     * (calculateLifetimeLicencePrice, 11-Aug engine). No downgrades, no refunds.
     * AMC re-bases automatically: the next AMC bill is priced from the licence's
     * user count at that time (amcQuote reads the prevailing count).
     * After payment the product's .lic must be re-issued — the client can
     * re-download it from the portal (fingerprint remembered).
     */
    public function createPerpetualUpgradeOrder(Licence $licence, int $newLimit): Order
    {
        $tenant = $licence->tenant;
        $plan = $licence->plan;

        if ($licence->kind !== 'perpetual' || $licence->status !== 'active') {
            throw new \RuntimeException('Only an active Perpetual (lifetime) licence can be upgraded here.');
        }
        if ($newLimit <= $licence->device_limit) {
            throw new \RuntimeException('Enter a user count higher than the current ' . $licence->device_limit
                . ' — a lifetime licence never reduces once purchased.');
        }

        $calcNew = $this->pricing->calculateLifetimeLicencePrice($plan, $newLimit);
        $calcCur = $this->pricing->calculateLifetimeLicencePrice($plan, (int) $licence->device_limit);

        if ($calcNew['custom'] || $calcNew['below_min'] || ! $calcNew['price']) {
            throw new \RuntimeException('That user count needs a custom quotation — WhatsApp 90000 98877 and we will price it for you.');
        }
        // A legacy/odd current count that the bands cannot price → manual territory.
        if ($calcCur['custom'] || $calcCur['below_min'] || ! $calcCur['price']) {
            throw new \RuntimeException('This licence predates the current price bands — WhatsApp 90000 98877 and we will raise the upgrade manually.');
        }

        $amount = round($calcNew['price'] - $calcCur['price'], 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Nothing extra to charge for that count — WhatsApp 90000 98877 and we will sort it manually.');
        }

        $quote = ['lines' => [[
            'type' => 'upgrade',
            'description' => sprintf('Lifetime licence upgrade %d → %d users — one-time price difference (₹%s − ₹%s, progressive pricing)',
                $licence->device_limit, $newLimit,
                number_format($calcNew['price']), number_format($calcCur['price'])),
            'qty' => 1,
            'unit' => $amount,
            'amount' => $amount,
        ]], 'subtotal' => $amount];

        $currency = $tenant->currency ?: 'INR';
        $this->convertCurrency($quote, $currency);
        $gstRate = $currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0.0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return Order::create([
            'number' => $this->nextOrderNumber(),
            'tenant_id' => $tenant->id,
            'licence_id' => $licence->id,
            'valid_until' => now()->addDays((int) Setting::get('quote_validity_days', 7))->toDateString(),
            'description' => sprintf('Upgrade — %s Perpetual, %d → %d users (lifetime price difference)',
                $plan->name, $licence->device_limit, $newLimit),
            'line_items' => $quote['lines'],
            'subtotal' => $quote['subtotal'],
            'tax_amount' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $currency,
            'gateway' => 'manual',
            'status' => 'created',
            'meta' => [
                'upgrade_new_limit' => $newLimit, 'plan_id' => $plan->id,
                'devices' => $newLimit, 'kind' => $licence->kind,
                'billing' => $licence->billing, 'deployment' => $licence->deployment,
                'perpetual_upgrade' => true,
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

        // Cloud console: stand up the hosted admin the first time a cloud tenant
        // is provisioned. Post-commit + best-effort — never blocks or rolls back
        // a payment, and no-ops once console_url is set.
        $this->console->ensureFor($order->tenant);

        return $order;
    }

    /**
     * Refund / credit-note (1.0 D5). A refund is a NEGATIVE row in the payments
     * ledger, so received() drops automatically and the books stay consistent —
     * no "refunded" enum that could disagree with the money (rev186 lesson).
     * Cannot exceed net received. Provisioning is NOT auto-reversed (a commercial
     * call handled by hand). Returns the credit-note payment row.
     */
    public function recordRefund(Order $order, float $amount, array $info = []): OrderPayment
    {
        $amount = round(abs($amount), 2);

        return DB::transaction(function () use ($order, $amount, $info) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $received = round((float) $order->payments()->sum('amount'), 2);

            if ($amount <= 0 || $amount > $received) {
                throw new \RuntimeException('Refund must be between '
                    . number_format(0.01, 2) . ' and ' . number_format($received, 2)
                    . ' (amount received on this order).');
            }

            $payment = OrderPayment::create([
                'order_id' => $order->id,
                'amount' => -$amount,                 // negative row = refund
                'gateway' => 'refund',
                'method' => $info['method'] ?? null,
                'reference' => $info['reference'] ?? null,
                'recorded_by' => $info['recorded_by'] ?? null,
                'note' => $info['reason'] ?? null,
                'credit_note_number' => $this->nextCreditNoteNumber(),
                'paid_at' => now(),
            ]);

            AuditLog::write('order.refunded', $order, [
                'amount' => $amount,
                'credit_note' => $payment->credit_note_number,
                'reason' => $info['reason'] ?? null,
                'received_after' => round((float) $order->payments()->sum('amount'), 2),
            ]);

            return $payment;
        });
    }

    /** GST-style FY credit-note series (same locked generator as invoices). */
    public function nextCreditNoteNumber(): string
    {
        return self::nextFySeriesNumber('order_payments', 'credit_note_number',
            Setting::get('credit_note_prefix', 'EPT-CN'));
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

        // 1. Issue, renew or upgrade the licence.
        if ($order->licence_id) {
            $licence = $order->licence;
            if (! empty($meta['upgrade_new_limit'])) {
                // Phase 5: pro-rata mid-period upgrade — capacity rises NOW,
                // the expiry date stays where it is (renewal bills the new size).
                $licence->update(['device_limit' => (int) $meta['upgrade_new_limit'], 'status' => 'active']);
            } elseif ($meta['renew_amc'] ?? false) {
                $this->licences->renewAmc($licence);
            } else {
                $this->licences->renew($licence);
                // Downgrade-at-renewal (12-Aug-2026): the scheduled reduction the
                // renewal was billed at applies now; the schedule is consumed
                // either way so a stale value never haunts the NEXT renewal.
                $apply = (int) ($meta['apply_device_limit'] ?? 0);
                $fresh = $licence->fresh();
                if ($apply > 0 && $apply !== (int) $fresh->device_limit) {
                    $fresh->update(['device_limit' => $apply, 'renewal_device_limit' => null]);
                } elseif ($fresh->renewal_device_limit !== null) {
                    $fresh->update(['renewal_device_limit' => null]);
                }
            }
            if (($meta['grandfather_migrated'] ?? false) && $licence->pricing_model === 'legacy') {
                $licence->update(['pricing_model' => 'v2', 'legacy_baseline_inr' => null]);
            }
        } elseif (isset($meta['plan_id'])) {
            $licence = $this->licences->issue($tenant, Plan::findOrFail($meta['plan_id']), [
                'kind' => $meta['kind'] ?? 'subscription',
                'billing' => $meta['billing'] ?? 'annual',
                'deployment' => ($meta['deployment'] ?? null) ?: ($tenant->deployment ?: 'client_hosted'),
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

        // 2b. Portal access guarantee (Phase 3, 6-Aug-2026): a client provisioned
        // from an admin-raised prospect quote has no portal login yet — create the
        // owner account now (random password; they set their own via the portal's
        // "Forgot password" email-OTP flow). Fail-soft: never blocks provisioning.
        if ($tenant->email) {
            try {
                if ($tenant->users()->count() === 0
                    && ! \App\Models\TenantUser::where('email', $tenant->email)->exists()) {
                    \App\Models\TenantUser::create([
                        'tenant_id' => $tenant->id,
                        'name' => $tenant->contact_name ?: $tenant->company_name,
                        'email' => $tenant->email,
                        'phone' => $tenant->phone,
                        'password' => \Illuminate\Support\Str::random(40),
                        'role' => 'owner',
                        'active' => 1,
                        'must_set_password' => true,
                    ]);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Owner portal-user auto-create failed: ' . $e->getMessage());
            }
        }

        // 3. Coupon redemption — counted once, on the order that provisioned.
        //    EPT-18: lock the coupon row and re-check the cap under the lock so a
        //    max_uses code redeemed on two concurrent orders serialises here — the
        //    second sees the cap met and does not double-count. Never throw: the
        //    discount is already frozen into this order and the money captured.
        if (! empty($meta['coupon_code'])) {
            $coupon = \App\Models\Coupon::where('code', $meta['coupon_code'])->lockForUpdate()->first();
            if ($coupon) {
                if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
                    AuditLog::write('coupon.overuse_blocked', $order, [
                        'code' => $coupon->code, 'used_count' => $coupon->used_count, 'max_uses' => $coupon->max_uses,
                    ]);
                } else {
                    $coupon->increment('used_count');
                }
            }
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

        // 1.0 Interakt payment confirmation — best-effort, only when configured
        // and a 'payment' template is approved. WaService never throws.
        if ($tenant->phone) {
            \App\Services\WaService::sendTemplate([
                'mobile' => $tenant->phone,
                'purpose' => 'payment',
                'bodyValues' => [
                    $tenant->company_name,
                    $symbol . number_format((float) $order->total, 2),
                    $order->number,
                    $invoice ? $invoice->number : 'to follow',
                    ($licence && $licence->expires_at) ? $licence->expires_at->toDateString() : '-',
                ],
                'kind' => 'payment',
            ]);
        }
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
        // Phase 0 fix (6-Aug-2026): trials run on the live v2 SmartEPT plan.
        // Fallback to the retired 'professional' plan keeps old installs working.
        $plan = Plan::where('code', 'smartept')->first()
            ?? Plan::where('code', 'professional')->firstOrFail();

        $tenant->update([
            'status' => 'trial',
            'trial_ends_at' => now()->addDays(7),
            'purge_after' => now()->addDays(14),
        ]);

        return $this->licences->issue($tenant, $plan, ['kind' => 'trial', 'device_limit' => 10]);
    }
}
