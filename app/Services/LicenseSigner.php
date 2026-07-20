<?php

namespace App\Services;

use App\Models\Licence;

/**
 * Signs offline node-locked license.lic tokens (EPT-29). Used by both the
 * smartept:issue-license command and the "Generate licence file" button in the
 * Licences screen. The PRIVATE key stays on Central and never ships to a client.
 */
class LicenseSigner
{
    public function privateKeyPath(): string
    {
        return storage_path('app/keys/license_private.pem');
    }

    public function available(): bool
    {
        return is_readable($this->privateKeyPath());
    }

    /** Build + sign the token for a licence, optionally locked to a machine fingerprint. */
    public function sign(Licence $licence, ?string $fingerprint = null): string
    {
        $priv = openssl_pkey_get_private((string) @file_get_contents($this->privateKeyPath()));
        if ($priv === false) {
            throw new \RuntimeException('Licence signing key missing/invalid at ' . $this->privateKeyPath()
                . ' — run GENERATE-LICENSE-KEYS.bat once.');
        }

        $licence->loadMissing(['tenant', 'plan']);
        $fp = trim((string) $fingerprint);

        $payload = [
            'v'            => 1,
            'key'          => $licence->key,
            'company'      => optional($licence->tenant)->company_name,
            'plan'         => optional($licence->plan)->code ?? optional($licence->plan)->name,
            'device_limit' => $licence->device_limit,
            'kind'         => $licence->kind,
            'deployment'   => $licence->deployment,
            'expires_at'   => optional($licence->expires_at)->toDateString(),
            'grace_days'   => 7,
            'features'     => $licence->features ?? [],
            'fingerprint'  => $fp !== '' ? $fp : null,
            'issued_at'    => now()->toIso8601String(),
        ];

        $b64 = $this->b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);

        return $b64 . '.' . $this->b64url($sig);
    }

    /** A safe .lic filename for a licence key. */
    public function filename(Licence $licence): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $licence->key) . '.lic';
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
