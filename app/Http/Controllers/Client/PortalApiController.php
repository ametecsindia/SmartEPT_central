<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DownloadArtifact;
use App\Models\DownloadLog;
use App\Models\Licence;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\StorageUsage;
use App\Services\BillingService;
use App\Services\MailService;
use App\Services\PricingService;
use App\Support\IndianStates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Tenant self-service API for the /client portal.
 * Full self-service (Ejaz, 15-Jul): renew, buy/upgrade, raise quotations —
 * every payment lands on the SAME golden path (BillingService::markPaid).
 * All queries are scoped to the logged-in user's tenant.
 */
class PortalApiController extends Controller
{
    public function __construct(
        private BillingService $billing,
        private PricingService $pricing,
        private MailService $mail,
    ) {
    }

    private function tenant()
    {
        return auth('client')->user()->tenant;
    }

    private function payUrl($order): string
    {
        return url('/pay/' . $order->number . '/' . CheckoutController::token($order));
    }

    // ---------- Read ----------

    public function overview()
    {
        $tenant = $this->tenant()->load('activeLicence.plan');
        $licence = $tenant->activeLicence;

        return response()->json([
            'tenant' => $tenant->only(['company_name', 'contact_name', 'email', 'phone', 'gstin',
                'deployment', 'status', 'ecosystem_customer', 'trial_ends_at']),
            'licence' => $licence ? [
                'id' => $licence->id,
                'key' => $licence->key,
                'plan' => $licence->plan->name,
                'plan_code' => $licence->plan->code,
                'kind' => $licence->kind,
                'billing' => $licence->billing,
                'deployment' => $licence->deployment,
                'device_limit' => $licence->device_limit,
                'renewal_device_limit' => $licence->renewal_device_limit,
                'devices_active' => $licence->activeDevices()->count(),
                'status' => $licence->status,
                'expires_at' => optional($licence->expires_at)->toDateString(),
                'days_left' => $licence->expires_at ? (int) now()->startOfDay()->diffInDays($licence->expires_at, false) : null,
            ] : null,
            'counts' => [
                'orders' => $tenant->orders()->count(),
                'unpaid' => $tenant->orders()->whereIn('status', ['created', 'quote'])->count(),
                'invoices' => $tenant->invoices()->count(),
            ],
            'whatsapp' => Setting::get('whatsapp_number', '919000098877'),
        ]);
    }

    public function licences()
    {
        $rows = $this->tenant()->licences()->with(['plan:id,name,code', 'devices'])->latest('id')->get()
            ->map(fn ($l) => [
                'id' => $l->id, 'key' => $l->key, 'plan' => $l->plan->name, 'kind' => $l->kind,
                'billing' => $l->billing, 'deployment' => $l->deployment, 'status' => $l->status,
                'device_limit' => $l->device_limit,
                'renewal_device_limit' => $l->renewal_device_limit,
                // .lic self-download is offered only when we already know the machine.
                'has_fingerprint' => (bool) $l->server_fingerprint,
                'expires_at' => optional($l->expires_at)->toDateString(),
                'devices' => $l->devices->map(fn ($d) => [
                    'device_uid' => $d->device_uid, 'hostname' => $d->hostname, 'status' => $d->status,
                    'activated_at' => optional($d->activated_at)->toDateString(),
                ]),
            ]);

        return response()->json($rows);
    }

    public function orders()
    {
        $rows = $this->tenant()->orders()->with('invoice:id,order_id,number')
            ->withSum('payments as received_sum', 'amount')->latest()->get()
            ->map(function ($o) {
                $received = round((float) ($o->received_sum ?? 0), 2);

                return [
                    'id' => $o->id, 'number' => $o->number, 'quote_number' => $o->quote_number,
                    'requested_by' => $o->requested_by, 'description' => $o->description,
                    'total' => $o->total, 'currency' => $o->currency, 'status' => $o->status,
                    'received' => $received,
                    'balance' => round(max(0, (float) $o->total - $received), 2),
                    'credit_due_date' => optional($o->credit_due_date)->toDateString(),
                    'provisioned' => (bool) $o->provisioned_at,
                    'paid_at' => optional($o->paid_at)->toDateString(),
                    'invoice_number' => optional($o->invoice)->number,
                    'created_at' => $o->created_at->toDateString(),
                    'pay_url' => in_array($o->status, ['created', 'quote']) ? $this->payUrl($o) : null,
                ];
            });

        return response()->json($rows);
    }

    public function invoices()
    {
        $rows = $this->tenant()->invoices()->latest()->get()
            ->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'date' => $i->date,
                'total' => $i->total, 'currency' => $i->currency, 'status' => $i->status,
            ]);

        return response()->json($rows);
    }

    public function plans()
    {
        $plans = Plan::with(['volumeTiers', 'perpetualBands'])->where('active', true)->orderBy('sort')->get()
            ->map(fn ($p) => [
                'code' => $p->code, 'name' => $p->name,
                'inr_annual' => $p->inr_annual, 'inr_monthly' => $p->inr_monthly,
                'min_devices' => $p->min_devices, 'features' => $p->features,
                'amc_pct' => (float) Setting::get('pricing_amc_pct', 18),
                'volume_tiers' => $p->volumeTiers->map(fn ($t) => [
                    'min' => $t->min_devices, 'max' => $t->max_devices, 'rate' => $t->rate_inr_annual,
                ]),
                'perpetual_bands' => $p->perpetualBands->map(fn ($b) => [
                    'min' => $b->min_users, 'max' => $b->max_users, 'price' => $b->price_inr,
                ]),
            ]);

        return response()->json($plans);
    }

    public function storage(Request $request)
    {
        $tenant = $this->tenant();
        $month = $request->query('month', now()->format('Y-m'));
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));

        $rows = StorageUsage::where('tenant_id', $tenant->id)
            ->whereBetween('date', [$start, $end])->orderBy('date')->get(['date', 'gb_used']);
        $avg = (float) $rows->avg('gb_used');

        return response()->json([
            'month' => $month,
            'is_cloud' => $tenant->deployment === 'cloud',
            'rows' => $rows,
            'avg_gb' => round($avg, 2),
            'billable_gb' => $avg > 0 ? (int) ceil(max($avg, PricingService::config()['storage_min_gb'])) : 0,
            'monthly_charge' => $avg > 0 ? round($this->pricing->storageMonthly($avg), 2) : 0,
        ]);
    }

    /**
     * R3 installers: what the "Install & Downloads" page needs — deployment-aware.
     * Cloud clients see their hosted console link + agent; client-hosted see both installers.
     */
    public function downloads()
    {
        $tenant = $this->tenant()->load('activeLicence');

        // Metadata (version / notes) from the managed catalogue, keyed by slug.
        $meta = [];
        try {
            $meta = DownloadArtifact::whereIn('slug', ['agent-windows', 'agent-mac', 'agent-linux', 'server-windows'])
                ->get()->keyBy('slug');
        } catch (\Throwable $e) {
            $meta = collect();
        }

        // An OS entry is offered when a file resolves for it (managed+published, or a legacy build drop).
        $agentPlatform = function (string $slug, string $platform, string $label) use ($meta) {
            $ready = (bool) PortalController::artifactPath($slug);
            $row = $meta[$slug] ?? null;

            return [
                'slug'     => $slug,
                'platform' => $platform,
                'label'    => $label,
                'ready'    => $ready,
                'version'  => $row->version ?? null,
                'notes'    => $row->notes ?? null,
                'size'     => $ready && $row ? $row->humanSize() : null,
            ];
        };

        $agents = [
            $agentPlatform('agent-windows', 'windows', 'Windows'),
            $agentPlatform('agent-mac', 'mac', 'macOS'),
            $agentPlatform('agent-linux', 'linux', 'Linux'),
        ];

        $serverReady = (bool) PortalController::artifactPath('server-windows');
        $serverRow = $meta['server-windows'] ?? null;

        // Anti-abuse quota: how many downloads this tenant has left this month.
        $quota = DownloadLog::quotaFor($tenant);
        $usedMonth = 0;
        try {
            $usedMonth = DownloadLog::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', now()->startOfMonth())->count();
        } catch (\Throwable $e) {
            $usedMonth = 0;
        }

        return response()->json([
            'deployment'  => $tenant->deployment,
            'console_url' => $tenant->console_url,
            'licence_key' => optional($tenant->activeLicence)->key,
            'agents'      => $agents,
            'agent_ready' => (bool) collect($agents)->firstWhere('ready', true), // any OS ready (back-compat)
            'admin_ready' => $serverReady,
            'server'      => [
                'slug'    => 'server-windows',
                'ready'   => $serverReady,
                'version' => $serverRow->version ?? null,
                'notes'   => $serverRow->notes ?? null,
                'size'    => $serverReady && $serverRow ? $serverRow->humanSize() : null,
            ],
            'quota'       => [
                'is_paid'         => $quota['is_paid'],
                'daily_per_app'   => $quota['daily'],
                'monthly'         => $quota['monthly'],
                'used_month'      => $usedMonth,
                'month_remaining' => max(0, $quota['monthly'] - $usedMonth),
            ],
        ]);
    }

    // ---------- Money (self-service) ----------

    public function quote(Request $request)
    {
        $data = $request->validate([
            'plan_code' => ['nullable', 'exists:plans,code'],
            'devices' => ['required', 'integer', 'min:1', 'max:100000'],
            'kind' => ['nullable', 'in:subscription,perpetual'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['nullable', 'in:client_hosted,cloud'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $tenant = $this->tenant();
        $plan = Plan::where('code', $data['plan_code'] ?? 'smartept')->firstOrFail();
        $kind = $data['kind'] ?? 'subscription';
        $quote = $kind === 'perpetual'
            ? $this->pricing->perpetualQuote($tenant, $plan, $data['devices'])
            : $this->pricing->subscriptionQuote($tenant, $plan, $data['devices'], $data['billing'] ?? 'annual', null);

        if (! empty($quote['below_min'])) {
            return response()->json(['message' => sprintf('Minimum On-Premise licence capacity is %d users.',
                (int) ($quote['min_users'] ?? 1))], 422);
        }

        if (! empty($quote['custom'])) {
            return response()->json(['custom' => true,
                'message' => 'For more than ' . number_format((int) ($quote['max_priced_users'] ?? 0)) . ' users, please request a custom quotation.'], 200);
        }

        // R3-7: apply a valid coupon as a negative line before GST.
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

        $gstRate = $tenant->currency === 'INR' ? (float) Setting::get('gst_rate', 18) : 0;
        $tax = round($quote['subtotal'] * $gstRate / 100, 2);

        return response()->json($quote + [
            'gst_rate' => $gstRate, 'tax' => $tax,
            'total' => round($quote['subtotal'] + $tax, 2),
            'currency' => $tenant->currency,
            'coupon' => $couponInfo,
        ]);
    }

    /**
     * Buy / upgrade — creates the order and hands back the pay link.
     * With as_quote=true it becomes a QUOTATION for management to pay
     * (requested_by = the logged-in portal user).
     */
    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'plan_code' => ['nullable', 'exists:plans,code'],
            'devices' => ['required', 'integer', 'min:1', 'max:100000'],
            'kind' => ['nullable', 'in:subscription,perpetual'],
            'billing' => ['nullable', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['nullable', 'in:client_hosted,cloud'],
            'as_quote' => ['boolean'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $tenant = $this->tenant();
        $plan = Plan::where('code', $data['plan_code'] ?? 'smartept')->firstOrFail();
        $kind = $data['kind'] ?? 'subscription';
        if ($kind === 'perpetual') {
            $calc = $this->pricing->calculateLifetimeLicencePrice($plan, (int) $data['devices']);
            if ($calc['below_min']) {
                return response()->json(['error' => sprintf('Minimum On-Premise licence capacity is %d users.', (int) $calc['min_users'])], 422);
            }
            if ($calc['custom']) {
                return response()->json(['error' => 'For more than ' . number_format((int) $calc['max_priced_users']) . ' users, please request a custom quotation.'], 422);
            }
        }

        $order = $this->billing->createOrder($tenant, $plan, $data['devices'], [
            'kind' => $kind,
            'billing' => $data['billing'] ?? 'annual',
            'deployment' => $kind === 'perpetual' ? 'client_hosted' : 'cloud',
            'as_quote' => (bool) ($data['as_quote'] ?? false),
            'requested_by' => ($data['as_quote'] ?? false) ? auth('client')->user()->name : null,
            'po_number' => $data['po_number'] ?? null,
            'coupon_code' => $data['coupon_code'] ?? null,
        ]);

        AuditLog::write($order->status === 'quote' ? 'client.quote_raised' : 'client.order_created', $order, [
            'total' => $order->total, 'by' => auth('client')->user()->email,
        ]);

        // Quotation raised → email the requester the quote number + pay link so
        // it can be forwarded straight to management. MailService never throws.
        if ($order->status === 'quote') {
            $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';
            $this->mail->send(
                auth('client')->user()->email,
                'SmartEPT — Quotation ' . $order->quote_number,
                "Hello " . auth('client')->user()->name . ",\n\n"
                . "Your quotation {$order->quote_number} is ready.\n\n"
                . "{$order->description}\n"
                . 'Total payable (incl. GST): ' . $symbol . number_format((float) $order->total, 2) . "\n\n"
                . "Management can approve and pay it directly at:\n" . $this->payUrl($order) . "\n\n"
                . 'The quotation is valid for ' . (int) \App\Models\Setting::get('quote_validity_days', 7) . ' days. Payment activates the licence instantly '
                . 'and the GST tax invoice is issued automatically.'
                . MailService::signature()
            );
        }

        return response()->json([
            'order' => $order->only(['id', 'number', 'quote_number', 'description', 'total', 'currency', 'status']),
            'pay_url' => $this->payUrl($order),
            'quote_print_url' => $order->quote_number ? url('/client/orders/' . $order->id . '/quote-print') : null,
        ], 201);
    }

    /**
     * One-click renewal of an existing licence — same period, same devices.
     */
    public function renew(Licence $licence)
    {
        abort_unless($licence->tenant_id === $this->tenant()->id, 404);

        if ($licence->kind === 'trial') {
            return response()->json(['error' => 'Trials are upgraded by buying a plan, not renewed.'], 422);
        }
        if ($licence->kind === 'perpetual') {
            return response()->json(['error' => 'Perpetual licences renew AMC through our team — ping us on WhatsApp.'], 422);
        }

        $order = $this->billing->createRenewalOrder($licence);
        AuditLog::write('client.renewal_order', $order, ['by' => auth('client')->user()->email]);

        return response()->json([
            'order' => $order->only(['id', 'number', 'description', 'total', 'currency', 'status']),
            'pay_url' => $this->payUrl($order),
        ], 201);
    }

    /**
     * Phase 5 (6-Aug-2026): pro-rata MID-PERIOD UPGRADE — add users today, pay
     * only the difference for the remaining days; expiry date unchanged.
     * 12-Aug-2026: PERPETUAL licences upgrade here too — one-time payment of the
     * progressive lifetime price difference (once purchased, seats only go UP).
     */
    public function upgrade(Request $request, Licence $licence)
    {
        abort_unless($licence->tenant_id === $this->tenant()->id, 404);

        $data = $request->validate([
            'devices' => ['required', 'integer', 'min:2', 'max:100000'],
        ]);

        try {
            $order = $licence->kind === 'perpetual'
                ? $this->billing->createPerpetualUpgradeOrder($licence, (int) $data['devices'])
                : $this->billing->createUpgradeOrder($licence, (int) $data['devices']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLog::write('client.upgrade_order', $order, [
            'by' => auth('client')->user()->email,
            'from' => $licence->device_limit, 'to' => (int) $data['devices'],
            'kind' => $licence->kind,
        ]);

        return response()->json([
            'order' => $order->only(['id', 'number', 'description', 'total', 'currency', 'status']),
            'pay_url' => $this->payUrl($order),
        ], 201);
    }

    /**
     * Downgrade-at-renewal (Ejaz, 12-Aug-2026): schedule a SEAT REDUCTION for
     * the next renewal of a Cloud subscription. Mid-period reductions stay
     * impossible; the renewal order bills the reduced size and provisioning
     * applies it. devices = null cancels the schedule. Never below the seats
     * currently in use, never at/above the current limit (that's an upgrade).
     */
    public function scheduleReduction(Request $request, Licence $licence)
    {
        abort_unless($licence->tenant_id === $this->tenant()->id, 404);

        if ($licence->kind !== 'subscription') {
            return response()->json(['error' => 'Only Cloud subscriptions reduce at renewal — a lifetime licence never reduces once purchased.'], 422);
        }

        $data = $request->validate([
            'devices' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $devices = $data['devices'] ?? null;

        if ($devices !== null) {
            if ((int) $devices >= (int) $licence->device_limit) {
                return response()->json(['error' => 'That is not a reduction — to add users, use the mid-period upgrade above.'], 422);
            }
            $active = $licence->activeDevices()->count();
            if ((int) $devices < $active) {
                return response()->json(['error' => "You currently have $active device(s) in use — free seats first, or schedule $active or more."], 422);
            }
        }

        $licence->update(['renewal_device_limit' => $devices !== null ? (int) $devices : null]);

        AuditLog::write('client.schedule_reduction', $licence, [
            'by' => auth('client')->user()->email,
            'current' => $licence->device_limit,
            'scheduled' => $devices,
        ]);

        return response()->json([
            'ok' => true,
            'renewal_device_limit' => $licence->fresh()->renewal_device_limit,
            'message' => $devices !== null
                ? "Done — your next renewal will bill and apply $devices user(s). You can cancel any time before renewing."
                : 'Scheduled reduction cancelled — renewals bill your current size again.',
        ]);
    }

    /**
     * Self-service .lic re-download (Ejaz, 12-Aug-2026) — after a perpetual
     * upgrade (or any reissue need) the client fetches a FRESH signed licence
     * file locked to the SAME machine we already know. Deliberately never
     * accepts a new fingerprint — moving machines stays a support action
     * (admin Shift machine), so a licence can't quietly migrate to a second PC.
     */
    public function licenseFile(Licence $licence, \App\Services\LicenseSigner $signer)
    {
        abort_unless($licence->tenant_id === $this->tenant()->id, 404);

        if (! in_array($licence->kind, ['perpetual', 'subscription'], true) || $licence->deployment !== 'client_hosted') {
            return response()->json(['error' => 'Licence files are for self-hosted servers — your Cloud console is licensed automatically.'], 422);
        }
        if ($licence->status !== 'active') {
            return response()->json(['error' => 'This licence is not active — renew or contact us first.'], 422);
        }
        if (! $signer->available()) {
            return response()->json(['error' => 'Licence file signing is temporarily unavailable — WhatsApp 90000 98877 and we will send your file.'], 422);
        }
        if (! $licence->server_fingerprint) {
            return response()->json(['error' => 'This licence is not bound to a machine yet. Validate once online from your server, or WhatsApp us the fingerprint shown on your SmartEPT Licence screen and we will issue the file.'], 422);
        }

        $token = $signer->sign($licence, $licence->server_fingerprint);
        AuditLog::write('licence.file_issued', $licence, [
            'key' => $licence->key,
            'locked' => true,
            'by' => 'client:' . auth('client')->user()->email,
        ]);

        return response()->json([
            'filename' => $signer->filename($licence),
            'token' => $token,
            'locked' => true,
        ]);
    }

    // ---------- Account ----------

    /**
     * Billing profile (GST). GSTIN/state decide how the tax on future invoices
     * is split (CGST+SGST vs IGST) — issued invoices keep their snapshot.
     */
    public function billingProfile()
    {
        $t = $this->tenant();

        return response()->json([
            'gstin' => $t->gstin,
            'state_code' => $t->state_code,
            'state_name' => IndianStates::name($t->state_code),
            'billing_address' => $t->billing_address,
            'states' => IndianStates::MAP,
        ]);
    }

    public function updateBillingProfile(Request $request)
    {
        $data = $request->validate([
            // 15-char GSTIN; loose shape check only — the checksum digit is the
            // GST portal's job, not ours (we must not block odd-but-real GSTINs).
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/i'],
            'state_code' => ['nullable', 'string', 'size:2', 'in:' . implode(',', array_keys(IndianStates::MAP))],
            'billing_address' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! empty($data['gstin'])) {
            $data['gstin'] = strtoupper($data['gstin']);
            // The first two GSTIN digits ARE the state — keep them consistent so
            // the invoice split never contradicts the printed GSTIN.
            $given = $data['state_code'] ?? null;
            if ($given && substr($data['gstin'], 0, 2) !== $given) {
                return response()->json(['error' => 'Your GSTIN starts with "' . substr($data['gstin'], 0, 2)
                    . '" but the state you picked is ' . $given
                    . '. The first two digits of a GSTIN are always the state code — please match them so your tax invoices stay correct.'], 422);
            }
            $data['state_code'] = $given ?: substr($data['gstin'], 0, 2);
        }

        $t = $this->tenant();
        $t->update($data);
        AuditLog::write('client.billing_profile_updated', $t, ['by' => auth('client')->user()->email]);

        return response()->json(['ok' => true] + $t->only(['gstin', 'state_code', 'billing_address']));
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = auth('client')->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['error' => 'Current password is wrong.'], 422);
        }

        // Master prompt §11: setting your own password clears the forced
        // create-your-own-password gate (temp password was backup only).
        $user->update(['password' => $data['password'], 'must_set_password' => false]);

        return response()->json(['ok' => true]);
    }

    // ---------- Support tickets ----------

    public function tickets()
    {
        $rows = \App\Models\SupportTicket::where('tenant_id', $this->tenant()->id)
            ->latest('id')->limit(100)->get()
            ->map(fn ($t) => [
                'id'          => $t->id,
                'subject'     => $t->subject,
                'message'     => $t->message,
                'category'    => $t->category,
                'status'      => $t->status,
                'admin_reply' => $t->admin_reply,
                'created_at'  => optional($t->created_at)->toDateTimeString(),
                'replied_at'  => optional($t->replied_at)->toDateTimeString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function createTicket(Request $request)
    {
        $data = $request->validate([
            'subject'  => ['required', 'string', 'max:160'],
            'message'  => ['required', 'string', 'max:4000'],
            'category' => ['nullable', 'in:general,billing,technical'],
        ]);

        $user = auth('client')->user();
        $tenant = $this->tenant();

        $ticket = \App\Models\SupportTicket::create([
            'tenant_id'       => $tenant->id,
            'subject'         => $data['subject'],
            'message'         => $data['message'],
            'category'        => $data['category'] ?? 'general',
            'status'          => 'open',
            'raised_by_name'  => $user->name,
            'raised_by_email' => $user->email,
        ]);

        // Notify the Ametecs support inbox (fail-soft — never block the client).
        try {
            $to = Setting::get('sales_email', 'sales@ametecsindia.com');
            app(MailService::class)->send(
                $to,
                'New support ticket #' . $ticket->id . ' — ' . $tenant->company_name,
                "Client: {$tenant->company_name}\nFrom: {$user->name} <{$user->email}>\nCategory: {$ticket->category}\n\nSubject: {$ticket->subject}\n\n{$ticket->message}" . MailService::signature()
            );
        } catch (\Throwable $e) {
            // ignore mail failures
        }

        return response()->json(['data' => ['id' => $ticket->id]], 201);
    }
}
