<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\WaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SmartEPT Central — in-app System Health / Diagnostics (Ametecs
 * troubleshooting-in-app standard). Lets Ejaz (non-technical) confirm the
 * platform is healthy and, when it isn't, see the exact Known-Issue fix — for
 * BOTH the Central portal itself and the client product it provisions.
 *
 * Each check returns key, label, status (ok|warn|down), plain-language detail,
 * and `fix` = the Help → Known-Issues card id the UI links to.
 */
class DiagnosticsController extends Controller
{
    /** GET /admin/api/diagnostics — run every self-check. */
    public function checks(Request $request): JsonResponse
    {
        $checks = [
            $this->checkDatabase(),
            $this->checkMigrations(),
            $this->checkScheduler(),
            $this->checkStorageWritable(),
            $this->checkOpcache(),
            $this->checkMail(),
            $this->checkWhatsApp(),
            $this->checkPayments(),
            $this->checkProductLink(),
            $this->checkPricingPlan(),
            $this->checkBuyFlow(),
            $this->checkLicenceSigning(),
            $this->checkRecentErrors(),
        ];

        $worst = 'ok';
        foreach ($checks as $c) {
            if ($c['status'] === 'down') { $worst = 'down'; break; }
            if ($c['status'] === 'warn') { $worst = 'warn'; }
        }

        return response()->json([
            'overall'    => $worst,
            'checked_at' => now()->toDateTimeString(),
            'checks'     => $checks,
        ]);
    }

    /**
     * POST /admin/api/scheduler/run-now (Ejaz, 6-Aug-2026): run all due
     * scheduled jobs immediately from the admin panel — instant heartbeat,
     * instant reminders, no terminal needed.
     */
    public function schedulerRunNow(): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('schedule:run');
            $out = trim(\Illuminate\Support\Facades\Artisan::output());
            \App\Models\AuditLog::write('scheduler.run_now', null, []);

            return response()->json([
                'ok' => true,
                'message' => 'Scheduled jobs ran. Re-run System Health — the scheduler row uses a 1-minute heartbeat, so it turns green when the AUTOMATIC runner is also working.',
                'output' => mb_substr($out, -1500),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /admin/api/scheduler/instructions (Ejaz, 6-Aug-2026): everything an
     * admin needs to set the scheduler up on ANY hosting — shown in the panel.
     */
    public function schedulerInstructions(): JsonResponse
    {
        $php = PHP_OS_FAMILY === 'Windows'
            ? PHP_BINARY
            : (is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php');

        return response()->json([
            'os' => PHP_OS_FAMILY,
            'base' => base_path(),
            'php' => $php,
            'cron_line' => '* * * * * cd ' . base_path() . ' && ' . $php . ' artisan schedule:run >> /dev/null 2>&1',
            'ssh_install' => '(crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo "* * * * * cd '
                . base_path() . ' && ' . $php . ' artisan schedule:run >> /dev/null 2>&1") | crontab -',
        ]);
    }

    /**
     * POST /admin/api/scheduler/install (Ejaz, 6-Aug-2026): register the
     * automatic 1-minute runner FROM THE ADMIN PANEL — no terminal needed.
     * Windows: creates a hidden Task Scheduler task that runs
     * "php artisan schedule:run" every minute. Linux: returns the cron line.
     */
    public function schedulerInstall(): JsonResponse
    {
        try {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (! function_exists('exec') || in_array('exec', $disabled, true)) {
                return response()->json(['ok' => false, 'error' => 'PHP exec() is disabled on this server — enable it in php.ini, or add the scheduler manually (Windows Task Scheduler / crontab).'], 422);
            }

            // ---------- Linux VPS (production smartept.com) ----------
            if (PHP_OS_FAMILY !== 'Windows') {
                // PHP_BINARY under FPM is php-fpm — find the CLI binary instead.
                $php = PHP_BINDIR . '/php';
                if (! is_executable($php)) {
                    $php = trim((string) shell_exec('command -v php 2>/dev/null')) ?: 'php';
                }

                $line = '* * * * * cd ' . base_path() . ' && ' . $php . ' artisan schedule:run >> /dev/null 2>&1';
                $sshFallback = "Run these two commands over SSH instead:\n"
                    . '  (crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo "' . $line . '") | crontab -' . "\n"
                    . '  crontab -l   (to verify the line is there)';

                // Idempotent install: strip any previous schedule:run line, append ours.
                $cmd = '(crontab -l 2>/dev/null | grep -v "artisan schedule:run"; echo ' . escapeshellarg($line) . ') | crontab - 2>&1';
                exec($cmd, $out, $code);

                $check = (string) shell_exec('crontab -l 2>/dev/null');
                if ($code !== 0 || strpos($check, 'artisan schedule:run') === false) {
                    return response()->json([
                        'ok' => false,
                        'error' => 'The hosting did not allow the web user to edit cron ('
                            . trim(implode(' ', $out)) . "). " . $sshFallback,
                    ], 422);
                }

                \Illuminate\Support\Facades\Artisan::call('schedule:run'); // instant heartbeat
                \App\Models\AuditLog::write('scheduler.installed', null, ['os' => 'linux', 'cron' => $line]);

                return response()->json([
                    'ok' => true,
                    'message' => 'Done! Cron now runs the SmartEPT scheduler every minute on this Linux server. Re-run System Health in a minute — the row will be green.',
                ]);
            }

            // ---------- Windows / Laragon (this PC) ----------

            $base = base_path();
            $php = PHP_BINARY;
            $bat = $base . DIRECTORY_SEPARATOR . 'scheduler-tick.bat';
            $vbs = $base . DIRECTORY_SEPARATOR . 'scheduler-tick.vbs';

            file_put_contents($bat,
                "@echo off\r\n"
                . 'cd /d "' . $base . "\"\r\n"
                . '"' . $php . '" artisan schedule:run >> "storage\\logs\\scheduler-tick.log" 2>&1' . "\r\n");

            // VBS wrapper = no black window flashing every minute.
            file_put_contents($vbs,
                'CreateObject("Wscript.Shell").Run """" & "' . str_replace('\\', '\\\\', $bat) . '" & """", 0, False' . "\r\n");

            $cmd = 'schtasks /Create /F /SC MINUTE /MO 1 /TN "SmartEPT Central Scheduler" /TR "wscript.exe \"' . $vbs . '\"" 2>&1';
            exec($cmd, $out, $code);

            if ($code !== 0) {
                return response()->json([
                    'ok' => false,
                    'error' => 'Windows refused to create the task: ' . implode(' ', $out)
                        . ' — try once from an elevated (Run as administrator) browser/Laragon, or create it manually in Task Scheduler pointing at scheduler-tick.vbs.',
                ], 422);
            }

            // Kick one run right away so the heartbeat goes green immediately.
            \Illuminate\Support\Facades\Artisan::call('schedule:run');

            \App\Models\AuditLog::write('scheduler.installed', null, ['task' => 'SmartEPT Central Scheduler']);

            return response()->json([
                'ok' => true,
                'message' => 'Done! Windows now runs the SmartEPT scheduler every minute automatically ("SmartEPT Central Scheduler" in Task Scheduler). Re-run System Health in a minute — the row will be green.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /admin/api/logs?lines=N — last N lines of storage/logs/laravel.log. */
    public function logs(Request $request): JsonResponse
    {
        $lines = min(max((int) $request->integer('lines', 200), 20), 500);
        $path  = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return response()->json([
                'exists' => false,
                'path'   => 'storage/logs/laravel.log',
                'text'   => '',
                'note'   => 'No log file yet — nothing has been written. That is normal on a fresh install.',
            ]);
        }

        clearstatcache(true, $path);
        $mtime = @filemtime($path);

        return response()->json([
            'exists'     => true,
            'path'       => 'storage/logs/laravel.log',
            'size_human' => $this->human((int) filesize($path)),
            'modified'   => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
            'lines'      => $lines,
            'text'       => $this->tail($path, $lines),
        ]);
    }

    // ---------------------------------------------------------------------

    private function checkDatabase(): array
    {
        try {
            $conn   = DB::connection();
            $driver = $conn->getDriverName();
            $conn->getPdo();
            $name   = $conn->getDatabaseName();

            if ($driver === 'sqlite') {
                return $this->row('database', 'Database connection', 'down',
                    'Central is connected to a local SQLite file instead of its MySQL database. Billing, '
                    . 'licences and client logins will not work correctly.',
                    'c-db');
            }

            return $this->row('database', 'Database connection', 'ok',
                "Connected to the {$driver} database \"{$name}\".");
        } catch (\Throwable $e) {
            return $this->row('database', 'Database connection', 'down',
                'Could not connect to the database. The MySQL service may be stopped, or the login '
                . 'details in .env may be wrong.',
                'c-db');
        }
    }

    private function checkMigrations(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = collect(glob(database_path('migrations/*.php')) ?: [])
                ->map(fn ($p) => basename($p, '.php'))->all();
            $pending = array_values(array_diff($files, $ran));

            if (count($pending) === 0) {
                return $this->row('migrations', 'Database updates', 'ok',
                    'All database updates have been applied.');
            }

            return $this->row('migrations', 'Database updates', 'warn',
                count($pending) . ' database update(s) have not been applied yet. A new feature may be '
                . 'missing or a screen may error until you run migrate.bat.',
                'c-migrate');
        } catch (\Throwable $e) {
            return $this->row('migrations', 'Database updates', 'warn',
                'Could not check for pending database updates (the database may be unreachable).',
                'c-db');
        }
    }

    private function checkStorageWritable(): array
    {
        $root  = storage_path('app');
        $probe = $root . DIRECTORY_SEPARATOR . '.smartept_write_test';

        try {
            if (@file_put_contents($probe, 'ok') === false) {
                return $this->row('storage', 'File storage', 'down',
                    "Central cannot write to its storage folder ({$root}). Invoices, quotes and installer "
                    . 'files may fail to save.',
                    'c-storage');
            }
            @unlink($probe);

            $free = @disk_free_space($root);
            if ($free !== false && $free < 536870912) {
                return $this->row('storage', 'File storage', 'warn',
                    'Storage is writable but the disk is nearly full (' . $this->human((int) $free)
                    . ' free). Free up space soon.',
                    'c-storage');
            }

            return $this->row('storage', 'File storage', 'ok',
                'Writable — Central can save invoices, quotes and files.');
        } catch (\Throwable $e) {
            return $this->row('storage', 'File storage', 'down',
                "Could not check the storage folder ({$root}).",
                'c-storage');
        }
    }

    private function checkOpcache(): array
    {
        if (! function_exists('opcache_get_status')) {
            return $this->row('opcache', 'PHP code cache (OPcache)', 'ok',
                'OPcache is not installed — PHP always reads the latest files.');
        }

        $status = @opcache_get_status(false);
        if (! $status || empty($status['opcache_enabled'])) {
            return $this->row('opcache', 'PHP code cache (OPcache)', 'ok',
                'OPcache is off — PHP always reads the latest files.');
        }

        $cfg = @opcache_get_configuration();
        $validate = $cfg['directives']['opcache.validate_timestamps'] ?? true;

        if ($validate === false || $validate === 0 || $validate === '0') {
            return $this->row('opcache', 'PHP code cache (OPcache)', 'warn',
                'OPcache is serving a frozen copy of the code (validate_timestamps is OFF). Changes will '
                . 'NOT take effect until PHP is fully restarted. Set opcache.validate_timestamps=1, then '
                . 'Laragon Stop All then Start All.',
                'c-opcache');
        }

        return $this->row('opcache', 'PHP code cache (OPcache)', 'ok',
            'OPcache is on and re-reads changed files — updates take effect normally.');
    }

    private function checkMail(): array
    {
        try {
            $host = Setting::get('mail_host');
        } catch (\Throwable $e) {
            $host = null;
        }

        if (! $host) {
            return $this->row('mail', 'Email sending', 'warn',
                'No SMTP mail server is set, so invoices, quotes, OTPs and credential emails cannot be sent. '
                . 'Add the mail settings in Settings → and use “Send test email”.',
                'c-mail');
        }

        return $this->row('mail', 'Email sending', 'ok',
            "Email is configured (SMTP {$host}). Use Settings → Send test email to confirm delivery.");
    }

    private function checkWhatsApp(): array
    {
        try {
            $cfg = WaService::config();
        } catch (\Throwable $e) {
            $cfg = null;
        }

        if (! $cfg) {
            return $this->row('whatsapp', 'WhatsApp (Interakt)', 'warn',
                'WhatsApp is not configured, so welcome/payment/renewal/OTP messages will not be sent. '
                . 'Add the Interakt API key in Settings → WhatsApp API and set status to active.',
                'c-wa');
        }

        return $this->row('whatsapp', 'WhatsApp (Interakt)', 'ok',
            'WhatsApp is configured. Sending still requires APPROVED templates in the WhatsApp Templates screen.');
    }

    private function checkPayments(): array
    {
        try {
            $razor  = Setting::get('razorpay_key_id') && Setting::get('razorpay_key_secret');
            $stripe = (bool) Setting::get('stripe_secret_key');
        } catch (\Throwable $e) {
            $razor = $stripe = false;
        }

        if (! $razor && ! $stripe) {
            return $this->row('payments', 'Online payments', 'warn',
                'No payment gateway is set up, so clients cannot pay online. Add Razorpay or Stripe keys in '
                . 'Settings. (You can still record manual/offline payments.)',
                'c-payments');
        }

        $which = trim(($razor ? 'Razorpay ' : '') . ($stripe ? 'Stripe' : ''));

        return $this->row('payments', 'Online payments', 'ok',
            "Online payments are set up ({$which}).");
    }

    private function checkRecentErrors(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return $this->row('errors', 'Recent errors', 'ok',
                'No error log yet — nothing has gone wrong.');
        }

        $tail   = $this->tail($path, 400);
        $cutoff = now()->subHour();
        $count  = 0;

        foreach (preg_split('/\r?\n/', $tail) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?\.(ERROR|CRITICAL|ALERT|EMERGENCY):/', $line, $m)) {
                try {
                    if (\Illuminate\Support\Carbon::parse($m[1])->greaterThanOrEqualTo($cutoff)) {
                        $count++;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        return $count === 0
            ? $this->row('errors', 'Recent errors', 'ok', 'No errors logged in the last hour.')
            : $this->row('errors', 'Recent errors', 'warn',
                "{$count} error(s) were logged in the last hour. Open the Application log tab above and use "
                . '“Copy for developer” if you need help.',
                'c-500');
    }

    // ---------------------------------------------------------------------

    /** The 1-minute scheduler heartbeat — drives dunning / renewal & trial reminders. */
    private function checkScheduler(): array
    {
        try {
            $beat = \Illuminate\Support\Facades\Cache::get('smartept:scheduler_heartbeat');
        } catch (\Throwable $e) {
            $beat = null;
        }

        if (! $beat) {
            return $this->row('scheduler', 'Background scheduler', 'warn',
                'The 1-minute background scheduler has not checked in yet. If it stays amber for a few '
                . 'minutes, Windows Task Scheduler (or cron) is not running "php artisan schedule:run" — '
                . 'renewal & trial reminders (dunning) and lifecycle jobs will not run.');
        }

        try {
            $age = (int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($beat), true);
        } catch (\Throwable $e) {
            $age = 999;
        }

        if ($age > 5) {
            return $this->row('scheduler', 'Background scheduler', 'down',
                "The background scheduler last ran {$age} minute(s) ago — it should run every minute. "
                . 'Start the "php artisan schedule:run" task so reminders and lifecycle jobs run.');
        }

        return $this->row('scheduler', 'Background scheduler', 'ok',
            'Ran within the last few minutes — renewal/trial reminders and lifecycle jobs are active.');
    }

    /** The link to the SmartEPT product app: cloud provisioning, SSO, suspend/enable. */
    private function checkPricingPlan(): array
    {
        try {
            $plan = Plan::where('code', 'smartept')->first();

            if (! $plan) {
                return $this->row('pricing', 'Pricing plan', 'down',
                    'The main SmartEPT pricing plan is missing from the database, so no quote or order can '
                    . 'be created: the New Order screen shows "plan code is invalid" and clients get '
                    . '"Request failed". Run migrate.bat to add it.',
                    'c-pricing');
            }

            if (! $plan->active) {
                return $this->row('pricing', 'Pricing plan', 'warn',
                    'The SmartEPT pricing plan exists but is switched off, so new quotes and orders will fail.',
                    'c-pricing');
            }

            $bands = $plan->volumeTiers()->count() + $plan->perpetualBands()->count();
            if ($bands === 0) {
                return $this->row('pricing', 'Pricing plan', 'warn',
                    'The SmartEPT plan has no price bands yet, so quotes may come out as custom. Run migrate.bat.',
                    'c-pricing');
            }

            return $this->row('pricing', 'Pricing plan', 'ok',
                'The SmartEPT pricing plan is present and priced.');
        } catch (\Throwable $e) {
            return $this->row('pricing', 'Pricing plan', 'warn',
                'Could not check the pricing plan (the database may be unreachable).',
                'c-db');
        }
    }

    /** Phase 6 (6-Aug-2026): the public Buy page's moving parts, in one row. */
    private function checkBuyFlow(): array
    {
        try {
            if (! view()->exists('buy')) {
                return $this->row('buy', 'Public Buy page', 'down',
                    'The /buy page template is missing — the website Buy buttons will show an error. '
                    . 'Restore resources/views/buy.blade.php from git.',
                    'c-buy');
            }

            $rzp = (bool) \App\Models\Setting::get('razorpay_key_id');
            $stripe = (bool) \App\Models\Setting::get('stripe_secret_key');

            if (! $rzp && ! $stripe) {
                return $this->row('buy', 'Public Buy page', 'warn',
                    'The Buy page works but NO payment gateway is configured (Razorpay/Stripe keys empty in '
                    . 'Settings), so buyers see the NEFT/WhatsApp fallback instead of paying online.',
                    'c-buy');
            }

            $usd = (float) \App\Models\Setting::get('usd_inr_rate', 88);

            return $this->row('buy', 'Public Buy page', 'ok',
                'Buy page live. Gateways: ' . ($rzp ? 'Razorpay ✓' : 'Razorpay —') . ' · '
                . ($stripe ? 'Stripe ✓ (USD ready, rate ₹' . $usd . '/$)' : 'Stripe — (USD buyers see fallback)') . '.');
        } catch (\Throwable $e) {
            return $this->row('buy', 'Public Buy page', 'warn',
                'Could not check the Buy page (the database may be unreachable).', 'c-buy');
        }
    }

    private function checkProductLink(): array
    {
        $url    = (string) config('services.product.provision_url');
        $secret = (string) config('services.product.provision_secret');
        $sso    = (string) config('services.product.sso_secret');

        if ($url === '' || $secret === '') {
            return $this->row('product_link', 'Hosted-console link (SmartEPT product)', 'warn',
                'Not configured, so Central cannot provision cloud consoles, one-click SSO, or suspend/'
                . 'enable a cloud client. Set PRODUCT_PROVISION_URL and PRODUCT_PROVISION_SECRET (and '
                . "PRODUCT_SSO_SECRET) in Central's .env to match the product server, then restart PHP.");
        }
        if ($sso === '') {
            return $this->row('product_link', 'Hosted-console link (SmartEPT product)', 'warn',
                'Provisioning is set, but one-click SSO is not (PRODUCT_SSO_SECRET is blank) — "Open Console" '
                . 'will ask the client to log in. Provisioning and suspend/enable still work.');
        }

        return $this->row('product_link', 'Hosted-console link (SmartEPT product)', 'ok',
            'Configured — Central can provision cloud consoles, SSO, and suspend/enable clients.');
    }

    /** The RSA key that signs offline .lic files — without it no licence can be issued. */
    private function checkLicenceSigning(): array
    {
        try {
            $signer = app(\App\Services\LicenseSigner::class);
            $path   = $signer->privateKeyPath();

            if (! $signer->available()) {
                return $this->row('licence_signing', 'Licence signing key', 'down',
                    "The licence signing key is missing or unreadable at {$path}. New .lic files cannot be "
                    . 'issued or renewed until it is in place — run GENERATE-LICENSE-KEYS.bat once on this server.');
            }
            if (@openssl_pkey_get_private((string) @file_get_contents($path)) === false) {
                return $this->row('licence_signing', 'Licence signing key', 'down',
                    "The signing key at {$path} is present but not a valid private key. Re-generate it with "
                    . 'GENERATE-LICENSE-KEYS.bat.');
            }

            return $this->row('licence_signing', 'Licence signing key', 'ok',
                'Present and valid — licences can be issued and signed.');
        } catch (\Throwable $e) {
            return $this->row('licence_signing', 'Licence signing key', 'warn',
                'Could not check the licence signing key.');
        }
    }

    private function row(string $key, string $label, string $status, string $detail, ?string $fix = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix');
    }

    private function tail(string $path, int $lines): string
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size === 0) {
            return '';
        }

        $fp = fopen($path, 'rb');
        if (! $fp) {
            return '';
        }

        $chunk = 8192;
        $pos = $size;
        $data = '';
        $newlines = 0;

        while ($pos > 0 && $newlines <= $lines) {
            $read = (int) min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $buf = fread($fp, $read);
            $data = $buf . $data;
            $newlines = substr_count($data, "\n");
        }
        fclose($fp);

        $all = preg_split('/\r?\n/', rtrim($data, "\r\n"));

        return implode("\n", array_slice($all, -$lines));
    }

    private function human(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $s) {
            if ($bytes >= $s) {
                return number_format($bytes / $s, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
