<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Licence;
use App\Services\LicenceService;
use Illuminate\Http\Request;

/**
 * Phone-home API used by SmartEPT product servers (client-hosted and cloud).
 * HARD WALL: licence metadata only — operational data never arrives here.
 * The licence key itself is the credential; all endpoints are rate-limited.
 */
class LicenseController extends Controller
{
    public function __construct(private LicenceService $licences)
    {
    }

    public function validateKey(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'fingerprint' => ['nullable', 'string', 'max:190'],
            'storage_gb' => ['nullable', 'numeric', 'min:0'],
            // Seat telemetry (Ejaz, 14-Aug-2026 — finding 1.3): the client console
            // reports what it actually has, so Central stops showing "0/25" for a
            // client that is running 14 people. Optional: older builds omit them.
            'users' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'employees' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'devices' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $result = $this->licences->validate($data['key'], $data['fingerprint'] ?? null);

        // Seat counts are recorded on EVERY answer, including a rejected one — a
        // blocked client is exactly the one we want an accurate headcount for.
        $this->recordReportedUsage($data);

        // EPT-27: record reported storage + govern the quota (email at 90%, pause at 100%).
        if ($result['ok'] ?? false) {
            $licence = \App\Models\Licence::where('key', $data['key'])->first();
            if ($licence && $licence->tenant_id) {
                if (isset($data['storage_gb'])) {
                    \App\Models\StorageUsage::updateOrCreate(
                        ['tenant_id' => $licence->tenant_id, 'date' => now()->toDateString()],
                        ['gb_used' => round((float) $data['storage_gb'], 3)]
                    );
                }
                $tenant = \App\Models\Tenant::with('activeLicence.plan')->find($licence->tenant_id);
                if ($tenant) {
                    $result['storage'] = $this->governStorage($tenant);
                }
            }
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 403);
    }

    /**
     * Finding 1.3 — store the seat counts the client console reported. Never
     * allowed to break the phone-home; a build that sends nothing leaves the
     * previous numbers (and reported_at) untouched.
     */
    private function recordReportedUsage(array $data): void
    {
        try {
            if (! isset($data['users']) && ! isset($data['employees']) && ! isset($data['devices'])) {
                return;
            }

            $licence = Licence::where('key', $data['key'])->first();
            if (! $licence) {
                return;
            }

            $licence->forceFill(array_filter([
                'reported_users' => isset($data['users']) ? (int) $data['users'] : null,
                'reported_employees' => isset($data['employees']) ? (int) $data['employees'] : null,
                'reported_devices' => isset($data['devices']) ? (int) $data['devices'] : null,
            ], fn ($v) => $v !== null) + ['reported_at' => now()])->save();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Licence usage report failed: ' . $e->getMessage());
        }
    }

    /** Compute the tenant's storage state, email once per escalation, return the wire block. */
    private function governStorage(\App\Models\Tenant $tenant): array
    {
        $ss = $tenant->storageStatus();
        $rank = ['OK' => 0, 'WARN' => 1, 'OVER' => 2];
        $prev = $tenant->storage_alert_level ?: 'OK';

        if (($rank[$ss['state']] ?? 0) > ($rank[$prev] ?? 0)) {
            $this->emailStorageAlert($tenant, $ss);
        }
        if ($ss['state'] !== $prev) {
            $tenant->forceFill(['storage_alert_level' => $ss['state']])->save();
        }

        return [
            'used_gb' => $ss['used_gb'],
            'quota_gb' => $ss['quota_gb'],
            'pct' => $ss['pct'],
            'state' => $ss['state'],
            'pause_screenshots' => $ss['state'] === 'OVER',
        ];
    }

    private function emailStorageAlert(\App\Models\Tenant $tenant, array $ss): void
    {
        try {
            $over = $ss['state'] === 'OVER';
            $to = $tenant->email ?: \App\Models\Setting::get('sales_email', 'sales@ametecsindia.com');
            $subject = $over
                ? 'SmartEPT storage is FULL — new screenshots paused (' . $tenant->company_name . ')'
                : 'SmartEPT storage at ' . $ss['pct'] . '% — ' . $tenant->company_name;
            $body = 'Hello ' . ($tenant->contact_name ?: $tenant->company_name) . ",\n\n"
                . 'Your SmartEPT evidence storage is at ' . $ss['pct'] . '% (' . $ss['used_gb'] . ' GB of ' . $ss['quota_gb'] . " GB).\n\n"
                . ($over
                    ? "Because the quota is full, NEW screenshots are paused to protect your data — activity, attendance and app/website tracking continue as normal. To resume screenshots, free up space (retention cleanup) or upgrade your storage plan.\n"
                    : "You are close to your storage limit. A retention cleanup or a storage upgrade now will avoid screenshots pausing when it fills.\n")
                . "\nManage it from the SmartEPT console -> Audit & Ops -> storage."
                . \App\Services\MailService::signature();
            app(\App\Services\MailService::class)->send($to, $subject, $body);
        } catch (\Throwable $e) {
            // alerting is best-effort — never break the phone-home
        }
    }

    public function activateDevice(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'device_uid' => ['required', 'string', 'max:190'],
            'hostname' => ['nullable', 'string', 'max:190'],
        ]);

        $licence = Licence::where('key', $data['key'])->where('status', 'active')->first();

        if (! $licence || $licence->isExpired()) {
            return response()->json(['ok' => false, 'reason' => 'invalid_licence'], 403);
        }

        $result = $this->licences->activateDevice($licence, $data['device_uid'], $data['hostname'] ?? null);

        return response()->json([
            'ok' => $result['ok'],
            'reason' => $result['reason'] ?? null,
            'device_limit' => $licence->device_limit,
            'devices_active' => $licence->activeDevices()->count(),
        ], $result['ok'] ? 200 : 409);
    }

    public function deactivateDevice(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'device_uid' => ['required', 'string', 'max:190'],
        ]);

        $licence = Licence::where('key', $data['key'])->first();

        if (! $licence) {
            return response()->json(['ok' => false, 'reason' => 'unknown_key'], 403);
        }

        $ok = $this->licences->deactivateDevice($licence, $data['device_uid']);

        return response()->json(['ok' => $ok], $ok ? 200 : 404);
    }
}
