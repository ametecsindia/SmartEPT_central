<?php

namespace App\Services;

use App\Models\MailLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The ONE door for transactional email (OTPs, payment receipts, quotes).
 * Plain-text bodies via Mail::raw — MAIL_MAILER=log on Laragon, SMTP in prod.
 *
 * Contract: NEVER throws. Billing, signup and webhooks must complete even
 * when the mailer is down; the failure is captured in mail_logs + laravel.log
 * so it can be re-sent manually.
 */
class MailService
{
    /** @return bool true when the mailer accepted the message */
    public function send(string $to, string $subject, string $body): bool
    {
        $status = 'sent';
        $error = null;

        $this->applyDbMailConfig();

        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
            Log::warning('Mail send failed: ' . $error, ['to' => $to, 'subject' => $subject]);
        }

        try {
            MailLog::create([
                'to_email' => $to,
                'subject' => $subject,
                'body' => $body,
                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            // Even the audit trail must not break the caller (e.g. table missing mid-migration).
            Log::warning('Mail log write failed: ' . $e->getMessage());
        }

        return $status === 'sent';
    }

    /** Apply the admin-configured SMTP settings (Settings screen) at send time; falls
     *  back to the .env mail config when unset. Never throws. */
    private function applyDbMailConfig(): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return;
            }
            $host = \App\Models\Setting::get('mail_host');
            if (! $host) {
                return; // use .env
            }
            $enc = strtolower((string) \App\Models\Setting::get('mail_encryption'));
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => (int) (\App\Models\Setting::get('mail_port') ?: 587),
                'mail.mailers.smtp.username' => \App\Models\Setting::get('mail_username') ?: null,
                'mail.mailers.smtp.password' => \App\Models\Setting::get('mail_password') ?: null,
                'mail.mailers.smtp.scheme' => $enc === 'ssl' ? 'smtps' : null,
                'mail.from.address' => \App\Models\Setting::get('mail_from_address') ?: config('mail.from.address'),
                'mail.from.name' => \App\Models\Setting::get('mail_from_name') ?: config('mail.from.name'),
            ]);
        } catch (\Throwable $e) {
            // keep .env config
        }
    }

    /** Shared plain-text signature so every mail ends the same way. */
    public static function signature(): string
    {
        $wa = '919000098877';
        $email = 'sales@ametecsindia.com';
        try {
            $wa = \App\Models\Setting::get('whatsapp_number', $wa) ?: $wa;
            $email = \App\Models\Setting::get('company_email', $email) ?: $email;
        } catch (\Throwable $e) {
            // defaults
        }

        return "\n\nSmartEPT by Ametecs — Empowering Productivity. Simplifying Management.\n"
            . 'Ametecs India Private Limited · WhatsApp ' . $wa . ' · ' . $email;
    }
}
