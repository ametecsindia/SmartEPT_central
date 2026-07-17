<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        if ($tenant->deployment !== 'cloud' || $tenant->console_url || $tenant->status !== 'active') {
            return;
        }

        $url = (string) config('services.product.provision_url');
        $secret = (string) config('services.product.provision_secret');
        if ($url === '' || $secret === '') {
            Log::warning('Cloud console not provisioned — product provisioning URL/secret not configured', ['tenant' => $tenant->id]);
            return;
        }

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
                ]);

            if (! $resp->successful()) {
                Log::error('Cloud console provisioning failed', ['tenant' => $tenant->id, 'status' => $resp->status(), 'body' => $resp->body()]);
                return;
            }

            $consoleUrl = $resp->json('console_url');
            if ($consoleUrl) {
                $tenant->forceFill(['console_url' => $consoleUrl])->save();
                Log::info('Cloud console provisioned', ['tenant' => $tenant->id, 'console_url' => $consoleUrl]);
            }
        } catch (\Throwable $e) {
            // Never let a provisioning hiccup roll back a real payment.
            Log::error('Cloud console provisioning threw', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * One-click SSO target for a cloud tenant: console_url with a signed,
     * short-lived ticket the product app trades for a session. Null if the
     * tenant has no console yet or SSO isn't configured.
     */
    public function ssoUrl(Tenant $tenant): ?string
    {
        $secret = (string) config('services.product.sso_secret');
        if ($tenant->deployment !== 'cloud' || ! $tenant->console_url || $secret === '') {
            return null;
        }

        $payload = ['email' => $tenant->email, 'tid' => (string) $tenant->id, 'exp' => time() + 120];
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, $secret);
        $sep = str_contains($tenant->console_url, '?') ? '&' : '?';

        return $tenant->console_url . $sep . 'sso=' . $body . '.' . $sig;
    }
}
