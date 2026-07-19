<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
            $this->checkStorageWritable(),
            $this->checkOpcache(),
            $this->checkMail(),
            $this->checkWhatsApp(),
            $this->checkPayments(),
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

        return response()->json([
            'exists'     => true,
            'path'       => 'storage/logs/laravel.log',
            'size_human' => $this->human((int) filesize($path)),
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
                "{$count} error(s) were logged in the last hour. Open the Log viewer below and use "
                . '“Copy for developer” if you need help.',
                'c-500');
    }

    // ---------------------------------------------------------------------

    private function row(string $key, string $label, string $status, string $detail, ?string $fix = null): array
    {
        return compact('key', 'label', 'status', 'detail', 'fix');
    }

    private function tail(string $path, int $lines): string
    {
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
