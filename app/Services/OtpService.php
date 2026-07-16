<?php

namespace App\Services;

use App\Models\ClientOtp;

/**
 * Email OTP for the /client portal (signup verification + password reset).
 * Codes are stored HASHED (sha256), valid 10 minutes, max 5 wrong attempts.
 * Channel today = email; an SMS/WhatsApp provider can be added later without
 * changing callers (issue()/verify() stay the same).
 */
class OtpService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;

    public function __construct(private MailService $mail)
    {
    }

    /**
     * Create + email a fresh 6-digit code. Any previous unconsumed code for the
     * same email+purpose is invalidated so only the newest code works.
     * Returns the plain code so the caller can decide whether to expose it
     * (test mode only — never in production responses).
     */
    public function issue(string $email, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        ClientOtp::where('email', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')->update(['consumed_at' => now()]);

        ClientOtp::create([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        $subject = $purpose === 'reset'
            ? 'SmartEPT — your password reset code'
            : 'SmartEPT — your verification code';

        // MailService never throws — Local/Laragon without a mailer must not
        // break signup; the code still lands in laravel.log / mail_logs.
        $this->mail->send(
            $email,
            $subject,
            "Your SmartEPT one-time code is: {$code}\n\n"
            . 'It is valid for ' . self::TTL_MINUTES . ' minutes. If you did not request this, please ignore this email.'
            . MailService::signature()
        );

        return $code;
    }

    /**
     * Check a code. Consumes it on success. Wrong tries are counted; after
     * MAX_ATTEMPTS the code is dead and a new one must be requested.
     */
    public function verify(string $email, string $purpose, string $code): bool
    {
        $otp = ClientOtp::where('email', $email)->where('purpose', $purpose)
            ->whereNull('consumed_at')->latest('id')->first();

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (! hash_equals($otp->code_hash, hash('sha256', $code))) {
            $otp->increment('attempts');

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        return true;
    }
}
