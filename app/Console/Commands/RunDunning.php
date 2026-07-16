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
        $this->expireLapsedLicences();
        $this->trialLifecycle();
        $this->enforcePurgeWindows();

        return self::SUCCESS;
    }

    /** One mail per licence per milestone: 30, 7, 1, 0 days before expiry. */
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

            if (! in_array($days, [30, 7, 1, 0], true) || ! $licence->tenant?->email) {
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
            $this->info("Renewal reminder ({$days}d): {$licence->key}");
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
            $tenant->licences()->whereIn('status', ['active', 'expired', 'suspended'])
                ->update(['status' => 'revoked']);
            $tenant->update(['status' => 'purged']);

            $this->warn("Purged (closed out): {$tenant->company_name}");
        }
    }
}
