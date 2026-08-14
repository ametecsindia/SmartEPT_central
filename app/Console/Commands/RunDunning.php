<?php

namespace App\Console\Commands;

use App\Models\Licence;
use App\Models\MailLog;
use App\Models\Tenant;
use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * R2-2: daily dunning & lifecycle automation (SmartPRS SubscriptionAlerts pattern).
 *
 *  - Renewal reminders at T-30 / T-7 / T-1 / T-0 before licence expiry.
 *  - Trial reminders at T-3 / T-1 / T-0, then trial → expired automation.
 *  - Licences past expiry + grace flip to expired (proactive, not just on phone-home).
 *  - Tenants past purge_after are closed out (status → purged, licences revoked;
 *    billing records are KEPT — GST/audit trail is never deleted).
 *
 * Every mail is deduped via mail_logs on an exact subject that carries the
 * licence key / tenant + milestone, so re-running the command never double-sends.
 */
class RunDunning extends Command
{
    protected $signature = 'smartept:dunning';

    protected $description = 'Send renewal/trial reminder emails and run licence lifecycle automation.';

    public function __construct(private MailService $mail)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->renewalReminders();
        $this->graceReminders();
        $this->expireLapsedLicences();
        $this->trialLifecycle();
        $this->enforcePurgeWindows();
        // Phase 5+ additions (Ejaz-approved, 6-Aug-2026) — beyond-SmartPRS follow-ups.
        $this->abandonedBuyRescue();
        $this->quoteExpiryChaser();
        $this->mdDailyDigest();

        return self::SUCCESS;
    }

    /** One mail per licence per milestone: 30, 15, 7, 3, 1, 0 days before expiry. */
    private function renewalReminders(): void
    {
        $today = now()->startOfDay();

        $licences = Licence::with('tenant', 'plan')
            ->where('status', 'active')
            ->where('kind', '!=', 'trial')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, $today->copy()->addDays(30)])
            ->get();

        foreach ($licences as $licence) {
            $days = (int) $today->diffInDays($licence->expires_at->copy()->startOfDay());

            if (! in_array($days, [30, 15, 7, 3, 1, 0], true) || ! $licence->tenant?->email) {
                continue;
            }

            $when = $days === 0 ? 'TODAY' : "in {$days} day" . ($days === 1 ? '' : 's');
            $subject = "SmartEPT licence {$licence->key} expires {$when} ({$licence->expires_at->toDateString()})";

            if (MailLog::where('subject', $subject)->exists()) {
                continue;
            }

            $body = "Dear {$licence->tenant->contact_name},\n\n"
                . "Your SmartEPT licence is due for renewal.\n\n"
                . "Licence key : {$licence->key}\n"
                . 'Plan        : ' . ($licence->plan->name ?? $licence->plan->code ?? '-') . "\n"
                . "Device seats: {$licence->device_limit}\n"
                . "Expires on  : {$licence->expires_at->toDateString()}\n"
                . "Grace days  : {$licence->grace_days} (monitoring continues during grace)\n\n"
                . 'Renew in a minute from your client portal: ' . rtrim(config('app.url'), '/') . "/client\n"
                . "Prefer to talk? WhatsApp us on 90000 98877 and we'll send a payment link.\n\n"
                . 'After the grace period the monitoring agents stop syncing, so renewing on time keeps your attendance and productivity records unbroken.'
                . MailService::signature();

            $this->mail->send($licence->tenant->email, $subject, $body);

            // 1.0 Interakt renewal reminder — same milestone, best-effort.
            if ($licence->tenant->phone) {
                \App\Services\WaService::sendTemplate([
                    'mobile' => $licence->tenant->phone,
                    'purpose' => 'renewal',
                    'bodyValues' => [
                        $licence->tenant->contact_name ?: $licence->tenant->company_name,
                        $licence->plan->name ?? $licence->plan->code ?? 'SmartEPT',
                        $when,
                        $licence->expires_at->toDateString(),
                        rtrim(config('app.url'), '/') . '/client',
                    ],
                    'kind' => 'renewal',
                ]);
            }

            $this->info("Renewal reminder ({$days}d): {$licence->key}");
        }
    }

    /**
     * SmartPRS-ladder grace reminders (Phase 5): a licence past its expiry but
     * still inside grace gets ONE mail per day (subject carries the date) until
     * it either renews or expireLapsedLicences() flips it.
     */
    private function graceReminders(): void
    {
        $today = now()->startOfDay();

        $inGrace = Licence::with('tenant', 'plan')
            ->where('status', 'active')
            ->where('kind', '!=', 'trial')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $today)
            ->get()
            ->filter(fn ($l) => ! $l->isExpired() && $l->tenant?->email);

        foreach ($inGrace as $licence) {
            $dayN = (int) $licence->expires_at->copy()->startOfDay()->diffInDays($today);
            $graceEnd = $licence->expires_at->copy()->addDays($licence->grace_days)->toDateString();
            $subject = "URGENT: SmartEPT licence {$licence->key} — grace day {$dayN} of {$licence->grace_days} ({$today->toDateString()})";

            if (MailLog::where('subject', $subject)->exists()) {
                continue;
            }

            $body = "Dear {$licence->tenant->contact_name},\n\n"
                . "Your SmartEPT licence expired on {$licence->expires_at->toDateString()} and is now in its grace period — "
                . "monitoring continues only until {$graceEnd}, after which the agents on your devices STOP syncing.\n\n"
                . 'Renew in one minute from your client portal: ' . rtrim(config('app.url'), '/') . "/client\n"
                . 'Need help or NEFT details? WhatsApp 90000 98877 — we respond immediately.'
                . MailService::signature();

            $this->mail->send($licence->tenant->email, $subject, $body);

            if ($licence->tenant->phone) {
                \App\Services\WaService::sendTemplate([
                    'mobile' => $licence->tenant->phone,
                    'purpose' => 'renewal',
                    'bodyValues' => [
                        $licence->tenant->contact_name ?: $licence->tenant->company_name,
                        $licence->plan->name ?? 'SmartEPT',
                        'GRACE — stops ' . $graceEnd,
                        $licence->expires_at->toDateString(),
                        rtrim(config('app.url'), '/') . '/client',
                    ],
                    'kind' => 'renewal',
                ]);
            }

            $this->warn("Grace reminder (day {$dayN}): {$licence->key}");
        }
    }

    /** Active licences past expiry + grace → expired (+ one notification). */
    private function expireLapsedLicences(): void
    {
        $lapsed = Licence::with('tenant')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->get()
            ->filter(fn ($l) => $l->isExpired());

        foreach ($lapsed as $licence) {
            $licence->update(['status' => 'expired']);

            $subject = "SmartEPT licence {$licence->key} has expired";

            if ($licence->tenant?->email && ! MailLog::where('subject', $subject)->exists()) {
                $body = "Dear {$licence->tenant->contact_name},\n\n"
                    . "Your SmartEPT licence {$licence->key} expired on {$licence->expires_at->toDateString()} and the grace period has now ended. "
                    . "Monitoring agents on your devices have stopped syncing.\n\n"
                    . 'Renew from your client portal to resume instantly — all your data and settings are intact: '
                    . rtrim(config('app.url'), '/') . "/client\n"
                    . 'Or WhatsApp 90000 98877 and we will help you straight away.'
                    . MailService::signature();

                $this->mail->send($licence->tenant->email, $subject, $body);
            }

            $this->warn("Expired: {$licence->key}");
        }
    }

    /** Trial reminders at T-3/T-1/T-0 + automatic trial → expired flip. */
    private function trialLifecycle(): void
    {
        $today = now()->startOfDay();

        $trials = Tenant::where('status', 'trial')->whereNotNull('trial_ends_at')->get();

        foreach ($trials as $tenant) {
            $ends = $tenant->trial_ends_at->copy()->startOfDay();

            if ($ends->lt($today)) {
                // Trial over → flip tenant + its trial licences.
                $tenant->update(['status' => 'expired']);
                $tenant->licences()->where('kind', 'trial')->where('status', 'active')
                    ->update(['status' => 'expired']);

                $subject = "Your SmartEPT trial has ended — {$tenant->company_name}";

                if ($tenant->email && ! MailLog::where('subject', $subject)->exists()) {
                    $body = "Dear {$tenant->contact_name},\n\n"
                        . "Your 7-day SmartEPT trial ended on {$ends->toDateString()}. Everything you set up — employees, policies, attendance history — is saved and waiting.\n\n"
                        . 'Pick a plan and go live in minutes: ' . rtrim(config('app.url'), '/') . "/client\n"
                        . 'Questions or a quick demo of the full product? WhatsApp 90000 98877.'
                        . MailService::signature();

                    $this->mail->send($tenant->email, $subject, $body);
                }

                $this->warn("Trial expired: {$tenant->company_name}");

                continue;
            }

            $days = (int) $today->diffInDays($ends);

            if (! in_array($days, [3, 1, 0], true) || ! $tenant->email) {
                continue;
            }

            $when = $days === 0 ? 'TODAY' : "in {$days} day" . ($days === 1 ? '' : 's');
            $subject = "Your SmartEPT trial ends {$when} — {$tenant->company_name}";

            if (MailLog::where('subject', $subject)->exists()) {
                continue;
            }

            $body = "Dear {$tenant->contact_name},\n\n"
                . "A quick reminder: your SmartEPT trial ends {$when} ({$ends->toDateString()}).\n\n"
                . "Liked what you saw — live dashboards, screenshots, attendance that feeds payroll? "
                . 'Choose a plan from your client portal and your setup carries over as-is: '
                . rtrim(config('app.url'), '/') . "/client\n"
                . 'Want help deciding the right plan and device count? WhatsApp 90000 98877.'
                . MailService::signature();

            $this->mail->send($tenant->email, $subject, $body);
            $this->info("Trial reminder ({$days}d): {$tenant->company_name}");
        }
    }

    /**
     * BEYOND-SmartPRS #1 (Ejaz-approved 6-Aug): abandoned-buy rescue.
     * A /buy order left unpaid for 3+ hours gets ONE friendly email resending
     * the pay link. Recovers lost sales silently. Skips anything partially paid
     * (credit clients are a human relationship, not an automation).
     */
    private function abandonedBuyRescue(): void
    {
        $orders = \App\Models\Order::with('tenant')
            ->where('status', 'created')
            ->whereBetween('created_at', [now()->subDays(7), now()->subHours(3)])
            ->get()
            ->filter(fn ($o) => ($o->meta['buy_flow'] ?? false)
                && ! ($o->meta['as_quote'] ?? false)
                && $o->quote_number === null
                && $o->received() <= 0.01
                && $o->tenant?->email);

        foreach ($orders as $order) {
            $subject = "Your SmartEPT order {$order->number} is reserved — complete it in one minute";

            if (MailLog::where('subject', $subject)->exists()) {
                continue;
            }

            $payUrl = rtrim(config('app.url'), '/') . '/pay/' . $order->number . '/'
                . \App\Http\Controllers\CheckoutController::token($order);
            $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';

            $body = "Hello {$order->tenant->contact_name},\n\n"
                . "You were one step away! Your SmartEPT order is saved and the price is locked:\n\n"
                . "{$order->description}\n"
                . 'Total (incl. GST where applicable): ' . $symbol . number_format((float) $order->total, 2) . "\n\n"
                . "Complete the secure payment here — your workspace activates the same minute:\n{$payUrl}\n\n"
                . 'Questions first? WhatsApp 90000 98877 — a human replies fast.'
                . MailService::signature();

            $this->mail->send($order->tenant->email, $subject, $body);
            $this->info("Abandoned-buy rescue: {$order->number}");
        }
    }

    /**
     * BEYOND-SmartPRS #2 (Ejaz-approved 6-Aug): quote-expiry chaser.
     * An unpaid quotation gets ONE reminder as its validity approaches the end
     * — no quotation dies silently.
     */
    private function quoteExpiryChaser(): void
    {
        $today = now()->startOfDay();

        $quotes = \App\Models\Order::with('tenant')
            ->where('status', 'quote')
            ->whereNotNull('quote_number')
            ->whereNotNull('valid_until')
            ->whereBetween('valid_until', [$today, $today->copy()->addDays(3)])
            ->where('created_at', '<', now()->subDay())
            ->get()
            ->filter(fn ($o) => $o->tenant?->email);

        foreach ($quotes as $order) {
            $validTill = $order->valid_until instanceof \Carbon\Carbon
                ? $order->valid_until->toDateString() : (string) $order->valid_until;
            $subject = "Quotation {$order->quote_number} expires on {$validTill} — SmartEPT";

            if (MailLog::where('subject', $subject)->exists()) {
                continue;
            }

            $payUrl = rtrim(config('app.url'), '/') . '/pay/' . $order->number . '/'
                . \App\Http\Controllers\CheckoutController::token($order);
            $symbol = $order->currency === 'INR' ? 'Rs. ' : '$';

            $body = "Hello {$order->tenant->contact_name},\n\n"
                . "A gentle reminder — your SmartEPT quotation is still open but expires on {$validTill}:\n\n"
                . "Quotation no. : {$order->quote_number}\n"
                . "{$order->description}\n"
                . 'Total payable : ' . $symbol . number_format((float) $order->total, 2) . "\n\n"
                . "Approve and pay securely here (activates instantly):\n{$payUrl}\n\n"
                . 'Want changes to the quote, or prefer NEFT? WhatsApp 90000 98877 and we will sort it right away.'
                . MailService::signature();

            $this->mail->send($order->tenant->email, $subject, $body);
            $this->info("Quote chaser: {$order->quote_number}");
        }
    }

    /**
     * BEYOND-SmartPRS #3 (Ejaz-approved 6-Aug): the MD daily money digest.
     * One email every morning — yesterday's leads, trials, quotations, payments
     * and the total outstanding balance. Sent once per day after 07:00 to
     * md_digest_email (falls back to company_email, then sales_email).
     */
    private function mdDailyDigest(): void
    {
        if (now()->hour < 7) {
            return; // send with the first run after 07:00
        }

        $subject = 'SmartEPT money digest — ' . now()->toDateString();

        if (MailLog::where('subject', $subject)->exists()) {
            return;
        }

        try {
            $to = \App\Models\Setting::get('md_digest_email')
                ?: (\App\Models\Setting::get('company_email') ?: \App\Models\Setting::get('sales_email', 'sales@ametecsindia.com'));

            $from = now()->subDay()->startOfDay();
            $till = now()->startOfDay();

            $leads = \App\Models\Lead::whereBetween('created_at', [$from, $till])->get();
            $trials = Tenant::where('status', 'trial')->whereBetween('created_at', [$from, $till])->get();
            $quotes = \App\Models\Order::whereBetween('created_at', [$from, $till])->whereNotNull('quote_number')->get();
            $payments = \App\Models\OrderPayment::with('order.tenant')
                ->whereBetween('paid_at', [$from, $till])->where('amount', '>', 0)->get();

            $outstandingOrders = \App\Models\Order::with('tenant')
                ->whereNotNull('provisioned_at')->where('status', '!=', 'paid')->get()
                ->filter(fn ($o) => $o->balance() > 0.01);
            $outstanding = round($outstandingOrders->sum(fn ($o) => $o->balance()), 2);

            $expiring = Licence::where('status', 'active')->where('kind', '!=', 'trial')
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [now()->startOfDay(), now()->addDays(7)])->count();

            $fmt = fn ($n) => 'Rs. ' . number_format((float) $n, 2);

            $body = "Good morning! Yesterday in SmartEPT money, at a glance:\n\n"
                . '• New leads        : ' . $leads->count()
                . ($leads->count() ? ' (' . $leads->take(5)->map(fn ($l) => $l->company ?: $l->name)->implode(', ') . ($leads->count() > 5 ? ', …' : '') . ')' : '') . "\n"
                . '• Trials started   : ' . $trials->count()
                . ($trials->count() ? ' (' . $trials->take(5)->pluck('company_name')->implode(', ') . ')' : '') . "\n"
                . '• Quotations raised: ' . $quotes->count()
                . ($quotes->count() ? ' — worth ' . $fmt($quotes->sum('total')) : '') . "\n"
                . '• Payments received: ' . $payments->count()
                . ($payments->count() ? ' — total ' . $fmt($payments->sum('amount'))
                    . ' (' . $payments->take(5)->map(fn ($p) => ($p->order?->tenant?->company_name ?: $p->order?->number) . ' ' . $fmt($p->amount))->implode('; ') . ')' : '') . "\n\n"
                . 'Standing position:' . "\n"
                . '• Credit balance outstanding: ' . $fmt($outstanding) . ' across ' . $outstandingOrders->count() . " order(s)\n"
                . '• Licences expiring within 7 days: ' . $expiring . "\n\n"
                . 'Details: /admin -> Leads · Trials · Orders · Credit clients.'
                . MailService::signature();

            $this->mail->send($to, $subject, $body);
            $this->info('MD digest sent to ' . $to);
        } catch (\Throwable $e) {
            $this->warn('MD digest failed: ' . $e->getMessage());
        }
    }

    /**
     * purge_after enforcement: close out long-expired tenants. Licences are
     * revoked and the tenant is marked purged. Financial records (orders,
     * invoices) are intentionally retained — statutory GST/audit data.
     */
    private function enforcePurgeWindows(): void
    {
        $due = Tenant::where('status', 'expired')
            ->whereNotNull('purge_after')
            ->where('purge_after', '<', now())
            ->get();

        foreach ($due as $tenant) {
            $tenant->licences()->whereIn('status', ['active', 'expired', 'suspended', 'superseded'])
                ->update(['status' => 'revoked']);
            $tenant->update(['status' => 'purged']);

            $this->warn("Purged (closed out): {$tenant->company_name}");
        }
    }
}
