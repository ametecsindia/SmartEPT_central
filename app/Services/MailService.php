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

    /** Shared plain-text signature so every mail ends the same way. */
    public static function signature(): string
    {
        return "\n\nSmartEPT by Ametecs — Empowering Productivity. Simplifying Management.\n"
            . 'Ametecs India Private Limited · WhatsApp 90000 98877 · sales@ametecsindia.com';
    }
}
