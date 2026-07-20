<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate the licence signing key pair (EPT-29) — cross-platform (Linux/Windows),
 * so it runs on the Linux-hosted Central with `php artisan smartept:make-keys`.
 *
 * Writes storage/app/keys/license_private.pem (SECRET — stays on Central) and
 * license_public.pem, and prints the public key to paste into the PRODUCT file
 * app/Services/LicenseFile.php (PUBLIC_KEY) before SourceGuardian-encrypting it.
 */
class MakeLicenseKeys extends Command
{
    protected $signature = 'smartept:make-keys {--force : Overwrite existing keys}';
    protected $description = 'Generate the RSA licence signing key pair (run ONCE).';

    public function handle(): int
    {
        $dir = storage_path('app/keys');
        $priv = $dir . '/license_private.pem';
        $pub = $dir . '/license_public.pem';

        if (file_exists($priv) && ! $this->option('force')) {
            $this->error('Keys already exist at ' . $dir . ' — refusing to overwrite. Use --force to replace them.');
            $this->warn('Replacing keys invalidates every licence file already issued.');
            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            $this->error('openssl_pkey_new failed — is the PHP openssl extension enabled?');
            return self::FAILURE;
        }
        openssl_pkey_export($res, $privatePem);
        $publicPem = openssl_pkey_get_details($res)['key'];

        file_put_contents($priv, $privatePem);
        @chmod($priv, 0600);
        file_put_contents($pub, $publicPem);

        $this->newLine();
        $this->info('Key pair written to ' . $dir);
        $this->line('  license_private.pem  — SECRET. Keep on Central only, back it up, never ship it.');
        $this->line('  license_public.pem   — paste the block below into the PRODUCT:');
        $this->line('                          app/Services/LicenseFile.php  ->  PUBLIC_KEY');
        $this->newLine();
        $this->line('----- COPY FROM HERE -----');
        $this->line(trim($publicPem));
        $this->line('----- TO HERE -----');
        $this->newLine();
        $this->warn('Then SourceGuardian-encrypt the product and rebuild the installer zip.');

        return self::SUCCESS;
    }
}
