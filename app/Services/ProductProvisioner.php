<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bridges SmartEPT Central → the SmartEPT product app for cloud tenants
 * (Ejaz 17-Jul). On a Managed-Cloud provision we stand up the tenant's hosted
 * console (Company + COMPANY_ADMIN login) over a secret-signed server call, and
 * store the returned console URL. "Open my Console" then mints a short-lived
 * SSO ticket so the client lands signed-in — no second password.
 *
 * Every call is best-effort and idempotent: a blank secret, a down product app,
 * or a re-provision must never break billing. The product side is keyed on the
 * tenant id, so a retry re-uses the same Company/user.
 */
class ProductProvisioner
{
    /** Provision (once) the hosted console for a cloud tenant; save console_url. */
    public function ensureFor(Tenant $tenant): void
    {
        // Cloud tenants only. Re-runnable (idempotent on the product side) so a slug
        // edit or first Save pushes through even after console_url is already set.
        // Covers trials too so trial clients get their branded console immediately.
        if ($tenant->deployment !== 'cloud' || ! in_array($tenant->status, ['active', 'trial'], true)) {
            return;
        }

        $base = $this->productBase();
        $secret = $this->provisionSecret();
        if ($base === '' || $secret === '') {
            Log::warning('Cloud console not provisioned — product URL/secret not set (Central → Settings → SmartEPT Product)', ['tenant' => $tenant->id]);
            return;
        }
        $url = $base . '/api/provision';

        try {
            $resp = Http::timeout(8)
                ->withHeaders(['X-Provision-Secret' => $secret])
                ->acceptJson()
                ->post($url, [
                    'external_tenant_id' => (string) $tenant->id,
                    'company_name'       => $tenant->company_name,
                    'admin_email'        => $tenant->email,
                    'admin_name'         => $tenant->contact_name,
                    'timezone'           => 'Asia/Kolkata',
                    'device_limit'       => optional($tenant->activeLicence)->device_limit,
                    // Central owns the storage allocation: seats x per-user free storage
                    // (+ any purchased top-up), pushed as MB. Re-pushed on every provision,
                    // so a seat upgrade or storage purchase updates the cap automatically.
                    'storage_quota_mb'   => $this->storageQuotaMb($tenant),
                    // Branded console slug (admin.smartept.com/<slug>). Editable per
                    // tenant; falls back to a clean slug of the company name.
                    'slug'               => $tenant->console_slug ?: Str::slug($tenant->company_name, ''),
                    // Central announces its own URL so the product's licence validation
                    // configures itself — no .env edit on the product (Ejaz, 12-Aug-2026).
                    'central_url'        => rtrim((string) config('app.url'), '/'),
                    // Per-tenant licensing (12-Aug-2026): hand the tenant's licence key
                    // over so their row on the shared install activates immediately —
                    // a cloud client never pastes a key by hand. Re-pushed on every
                    // provision, so a renewal/upgrade refreshes the product side too.
                    'licence_key'        => optional($tenant->activeLicence)->key,
                    // CONSOLE MAIL AUTOPILOT (Ejaz, 13-Aug-2026): a fresh console
                    // has no SMTP, so its OTP/credential emails silently died in
                    // the log mailer. Central owns the relay (Settings → Email) —
                    // hand it over here; the console adopts it as its global SMTP
                    // only when none is configured there yet.
                    'mail'               => $this->mailConfig(),
                ]);

            if (! $resp->successful()) {
                Log::error('Cloud console provisioning failed', ['tenant' => $tenant->id, 'status' => $resp->status(), 'body' => $resp->body()]);
                return;
            }

            // Was this tenant already live on the product BEFORE this call? Decides
            // whether the "your console is ready" mail below is owed (see 1-Sep note).
            $hadConsole = (bool) $tenant->console_url;

            $consoleUrl = $resp->json('console_url');
            if ($consoleUrl) {
                $tenant->forceFill(['console_url' => $consoleUrl])->save();
                Log::info('Cloud console provisioned', ['tenant' => $tenant->id, 'console_url' => $consoleUrl]);
            }

            // ROOT-CAUSE FIX (Ejaz, 13-Aug-2026): on FIRST provision the console
            // creates the COMPANY_ADMIN with a temp password and returns it here —
            // and Central used to throw it away. Nobody ever told the client, so
            // every cloud client's slug login failed with "credentials do not
            // match" (their portal password belongs to a DIFFERENT system). Email
            // it via Central's working SMTP; the console forces a password change
            // on first sign-in (must_change_password), so the temp is single-use.
            // temp_password is null on re-provision — this mails exactly once.
            // 1-Sep-2026: this mail used to be gated on temp_password ALONE. The
            // product returns that only on the FIRST provision, so a tenant whose
            // first attempt failed after the console was already created (and every
            // re-provision) got a working console and was never told it existed —
            // no URL, no sign-in instructions, nothing. Now the client is told
            // whenever their console comes into existence; the password block is
            // included only when the product actually handed one over, and the
            // passwordless portal route covers the rest.
            $temp = (string) $resp->json('temp_password', '');
            if ($tenant->email && ($temp !== '' || (! $hadConsole && $consoleUrl))) {
                $signIn = $temp !== ''
                    ? "Sign-in email: {$tenant->email}\n"
                        . "Temporary password: {$temp}\n\n"
                        . "You will be asked to set your own password on first sign-in.\n"
                        . "Tip: you can also open the console without any password from your client portal (single sign-on).\n"
                    : "To sign in, open your client portal and click \"Open my SmartEPT Console\" — it signs you in\n"
                        . "with one click, no password needed. If you would rather use a password, choose\n"
                        . "\"Forgot password\" on the console and we will email you a reset link.\n";

                app(MailService::class)->send(
                    $tenant->email,
                    'Your SmartEPT console is ready — ' . $tenant->company_name,
                    "Hello {$tenant->contact_name},\n\n"
                    . "Your company's SmartEPT console is ready.\n\n"
                    . 'Console: ' . ($consoleUrl ?: $this->productBase()) . "\n"
                    . $signIn
                    . "\n— SmartEPT" . MailService::signature()
                );
                Log::info('Console details emailed to tenant owner',
                    ['tenant' => $tenant->id, 'with_password' => $temp !== '']);
            }
        } catch (\Throwable $e) {
            // Never let a provisioning hiccup roll back a real payment.
            Log::error('Cloud console provisioning threw', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Allocated storage for a tenant, in MB, from the single source of truth
     * Tenant::storageQuotaGb() (per-client override -> plan GB -> seats x per-user).
     * Returns null when that resolves to 0 (no override, no plan GB, no seats yet),
     * so the product keeps its default rather than capping the console prematurely.
     */
    private function storageQuotaMb(Tenant $tenant): ?int
    {
        $gb = $tenant->storageQuotaGb();

        return $gb > 0 ? (int) round($gb * 1024) : null; // GB -> MB
    }

    /** Central's admin-configured SMTP, for the console to inherit; null when unset. */
    private function mailConfig(): ?array
    {
        $host = trim((string) Setting::get('mail_host'));
        if ($host === '') {
            return null;
        }

        return [
            'host'         => $host,
            'port'         => (int) (Setting::get('mail_port') ?: 587),
            'username'     => (string) Setting::get('mail_username'),
            'password'     => (string) Setting::get('mail_password'),
            'encryption'   => (string) (Setting::get('mail_encryption') ?: 'tls'),
            'from_address' => (string) Setting::get('mail_from_address'),
            'from_name'    => (string) (Setting::get('mail_from_name') ?: 'SmartEPT'),
        ];
    }

    /** Push a suspend/enable to the tenant's hosted console (cloud tenants only). */
    public function setStatus(Tenant $tenant, string $centralStatus): void
    {
        if ($tenant->deployment !== 'cloud') {
            return; // client-hosted servers are not reachable from Central
        }
        $base = $this->productBase();
        $secret = $this->provisionSecret();
        if ($base === '' || $secret === '') {
            return;
        }

        // active/trial keep the console open; anything else blocks it (hard cut-off).
        $productStatus = in_array($centralStatus, ['active', 'trial'], true) ? 'ACTIVE' : 'SUSPENDED';
        $url = $base . '/api/provision/status';

        try {
            Http::timeout(8)
                ->withHeaders(['X-Provision-Secret' => $secret])
                ->acceptJson()
                ->post($url, [
                    'external_tenant_id' => (string) $tenant->id,
                    'status'             => $productStatus,
                ]);
            Log::info('Tenant status pushed to hosted console', ['tenant' => $tenant->id, 'status' => $productStatus]);
        } catch (\Throwable $e) {
            Log::error('Tenant status push failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * One-click SSO target for a cloud tenant: console_url with a signed,
     * short-lived ticket the product app trades for a session. Null if the
     * tenant has no console yet or SSO isn't configured.
     */
    public function ssoUrl(Tenant $tenant): ?string
    {
        $secret = $this->ssoSecret();
        if ($tenant->deployment !== 'cloud' || ! $tenant->console_url || $secret === '') {
            return null;
        }

        $payload = ['email' => $tenant->email, 'tid' => (string) $tenant->id, 'exp' => time() + 120];
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, $secret);
        $sep = str_contains($tenant->console_url, '?') ? '&' : '?';

        return $tenant->console_url . $sep . 'sso=' . $body . '.' . $sig;
    }

    /**
     * The hosted product's BASE URL. Preferred source is Central → Settings →
     * SmartEPT Product (so no .env editing). Falls back to the .env value, from
     * which any trailing /api/provision path is stripped to leave the base.
     */
    private function productBase(): string
    {
        $b = trim((string) Setting::get('product_base_url', ''));
        if ($b !== '') {
            return rtrim($b, '/');
        }
        $u = (string) config('services.product.provision_url');
        return rtrim(preg_replace('#/api/provision.*$#', '', $u), '/');
    }

    /** Provisioning secret — Settings first, then .env. */
    private function provisionSecret(): string
    {
        return (string) (Setting::get('product_provision_secret') ?: config('services.product.provision_secret'));
    }

    /** SSO shared secret — Settings first, then .env. */
    private function ssoSecret(): string
    {
        return (string) (Setting::get('product_sso_secret') ?: config('services.product.sso_secret'));
    }
}
