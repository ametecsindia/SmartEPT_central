<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Licence;
use App\Models\ProductUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Update phone-home for on-prem SmartEPT servers (Ejaz, 1-Sep-2026).
 *
 * Same wall as licensing: the licence key is the credential and nothing but
 * update metadata crosses. This endpoint deliberately does NOT call
 * LicenceService::validate() — a version check must never bind a machine
 * fingerprint, move last_validated_at, or write a daily verification entry.
 * It reads the licence, decides, and leaves it exactly as it found it.
 *
 * The package itself is fetched with a single-use token so the licence key
 * never travels in a URL (URLs land in access logs and proxy caches).
 */
class UpdateController extends Controller
{
    private const TOKEN_TTL_MINUTES = 60;

    /** POST /api/v1/updates/check */
    public function check(Request $request)
    {
        $data = $request->validate([
            'key'             => ['required', 'string', 'max:190'],
            'fingerprint'     => ['nullable', 'string', 'max:190'],
            'current_version' => ['required', 'string', 'max:60'],
            'product'         => ['nullable', 'string', 'max:40'],
            'channel'         => ['nullable', 'in:stable,beta'],
            'installation_id' => ['nullable', 'string', 'max:190'],
        ]);

        $licence = $this->gate($data);
        if (is_array($licence)) {
            return response()->json($licence, 403);
        }

        $update = ProductUpdate::latestFor(
            $data['current_version'],
            $data['product'] ?? 'smartept',
            $data['channel'] ?? 'stable'
        );

        if (! $update) {
            return response()->json([
                'ok'               => true,
                'update_available' => false,
                'current_version'  => $data['current_version'],
                'message'          => 'This server is already on the latest published version.',
            ]);
        }

        $token = Str::random(48);
        Cache::put('sept_update_dl:' . $token, [
            'update_id' => $update->id,
            'licence'   => $licence->id,
        ], now()->addMinutes(self::TOKEN_TTL_MINUTES));

        $this->trail($licence, 'update.offered', $update, $data);

        return response()->json([
            'ok'               => true,
            'update_available' => true,
            'product'          => $update->product,
            'version'          => $update->version,
            'minimum_version'  => $update->min_version,
            'title'            => $update->title,
            'notes'            => $update->notes,
            'size_bytes'       => $update->size_bytes,
            'package_hash'     => $update->sha256,
            'signature'        => $update->signature,
            'download_url'     => url('/api/v1/updates/download/' . $token),
            'token_expires_in' => self::TOKEN_TTL_MINUTES * 60,
            'released_at'      => $update->released_at?->toIso8601String(),
        ]);
    }

    /**
     * GET /api/v1/updates/download/{token}
     * Single-use-ish: the token dies after TOKEN_TTL_MINUTES. Kept valid for the
     * window rather than burned on first byte, so a dropped connection can resume
     * without the server having to re-check.
     */
    public function download(string $token)
    {
        $entry = Cache::get('sept_update_dl:' . $token);
        if (! $entry) {
            return response()->json(['ok' => false, 'reason' => 'token_expired',
                'message' => 'This download link has expired. Press "Check for Update" again.'], 410);
        }

        $update = ProductUpdate::find($entry['update_id']);
        if (! $update || ! $update->is_published || ! $update->fileExists()) {
            return response()->json(['ok' => false, 'reason' => 'package_unavailable',
                'message' => 'That package is no longer available.'], 404);
        }

        $licence = Licence::find($entry['licence']);
        if ($licence) {
            $this->trail($licence, 'update.downloaded', $update, []);
        }

        return response()->download($update->path(), $update->filename, [
            'Content-Type'         => 'application/zip',
            'X-SmartEPT-Version'   => $update->version,
            'X-SmartEPT-Sha256'    => (string) $update->sha256,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** The licence, or an error body. Read-only — never mutates the licence row. */
    private function gate(array $data): Licence|array
    {
        $licence = Licence::where('key', $data['key'])->first();

        if (! $licence) {
            return ['ok' => false, 'update_available' => false, 'reason' => 'unknown_key',
                'message' => 'This licence key is not recognised by SmartEPT Central.'];
        }
        if ($licence->status !== 'active') {
            return ['ok' => false, 'update_available' => false, 'reason' => 'licence_' . $licence->status,
                'message' => 'Updates need an active licence. This licence is ' . $licence->status . '.'];
        }
        if ($licence->isExpired()) {
            return ['ok' => false, 'update_available' => false, 'reason' => 'licence_expired',
                'message' => 'Updates need a current licence. Renew from the client portal, then check again.'];
        }
        // NO fingerprint check here, deliberately (1-Sep-2026). There are TWO
        // fingerprints in this product and they are not interchangeable:
        //   - the 40-char MACHINE id (SMBIOS/machine-id), which locks a .lic file
        //     and which licenseFile() stores in server_fingerprint;
        //   - the 64-char sha256(app.key|hostname) that LicenseClient phones home
        //     with, and which LicenceService::validate() binds on first contact.
        // Whichever wrote server_fingerprint last, the other one can never match,
        // so comparing them here refused every offline-licensed install. The key
        // is the credential, and an update package is a public product build, not
        // client data. Machine cloning is still caught where it belongs: the daily
        // validate() phone-home, which logs the rejection and alerts sales.

        // Perpetual licences buy the version they own; AMC is what buys new builds.
        if ($licence->kind === 'perpetual' && ! $licence->amcActive()) {
            return ['ok' => false, 'update_available' => false, 'reason' => 'amc_expired',
                'message' => 'Your AMC has ended, so new versions are not included. Renew the AMC to receive updates.'];
        }

        return $licence;
    }

    /** Fail-soft history entry — an audit write must never break an update check. */
    private function trail(Licence $licence, string $action, ProductUpdate $update, array $data): void
    {
        try {
            AuditLog::write($action, $licence, [
                'version'         => $update->version,
                'from_version'    => $data['current_version'] ?? null,
                'installation_id' => $data['installation_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // ignored on purpose
        }
    }
}
