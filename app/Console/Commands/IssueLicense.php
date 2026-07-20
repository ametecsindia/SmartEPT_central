<?php

namespace App\Console\Commands;

use App\Models\Licence;
use Illuminate\Console\Command;

/**
 * Issue an offline, node-locked license.lic for a client (EPT-29).
 *
 *   php artisan smartept:issue-license SEPT-AKEY-F5KW-LIZZ-C88F --fingerprint=<client machine fp>
 *
 * Signs the licence details with the PRIVATE key (storage/app/keys/license_private.pem,
 * kept offline, never shipped). The client's product verifies it with the embedded
 * public key — no network needed.
 */
class IssueLicense extends Command
{
    protected $signature = 'smartept:issue-license
        {key : The licence key to issue a file for}
        {--fingerprint= : Client machine fingerprint from their Licence screen (locks the file to that PC)}
        {--out= : Output path (default: storage/app/licenses/<key>.lic)}
        {--private= : Private key PEM path (default: storage/app/keys/license_private.pem)}';

    protected $description = 'Sign an offline, node-locked license.lic file for a client from a licence record.';

    public function handle(): int
    {
        $licence = Licence::with(['tenant', 'plan'])->where('key', $this->argument('key'))->first();
        if (! $licence) {
            $this->error('Licence key not found: ' . $this->argument('key'));
            return self::FAILURE;
        }

        $privPath = $this->option('private') ?: storage_path('app/keys/license_private.pem');
        if (! is_readable($privPath)) {
            $this->error("Private key not found: {$privPath}");
            $this->line('Create the key pair once with deployment\\installers\\GENERATE-LICENSE-KEYS.bat');
            return self::FAILURE;
        }
        $priv = openssl_pkey_get_private((string) file_get_contents($privPath));
        if ($priv === false) {
            $this->error('Could not load the private key (is it a valid PEM?).');
            return self::FAILURE;
        }

        $fingerprint = trim((string) $this->option('fingerprint'));
        if ($fingerprint === '') {
            $this->warn('No --fingerprint given — this file will run on ANY machine (not node-locked).');
        }

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
            'fingerprint'  => $fingerprint !== '' ? $fingerprint : null,
            'issued_at'    => now()->toIso8601String(),
        ];

        $b64 = $this->b64url((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        openssl_sign($b64, $sig, $priv, OPENSSL_ALGO_SHA256);
        $token = $b64 . '.' . $this->b64url($sig);

        $out = $this->option('out') ?: storage_path('app/licenses/' . $this->safeName($licence->key) . '.lic');
        @mkdir(dirname($out), 0775, true);
        file_put_contents($out, $token . "\n");

        $this->newLine();
        $this->info('Licence file written:');
        $this->line('  ' . $out);
        $this->line('  Company : ' . ($payload['company'] ?? '—'));
        $this->line('  Plan    : ' . ($payload['plan'] ?? '—') . '   Devices: ' . ($payload['device_limit'] ?? '—'));
        $this->line('  Expires : ' . ($payload['expires_at'] ?? 'perpetual (never)'));
        $this->line('  Locked  : ' . ($fingerprint !== '' ? $fingerprint : 'ANY machine (not locked)'));
        $this->newLine();
        $this->line('Send this .lic file to the client — they import it on their Licence screen.');

        return self::SUCCESS;
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function safeName(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $s);
    }
}
