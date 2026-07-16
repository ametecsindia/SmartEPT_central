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
        ]);

        $result = $this->licences->validate($data['key'], $data['fingerprint'] ?? null);

        return response()->json($result, $result['ok'] ? 200 : 403);
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
