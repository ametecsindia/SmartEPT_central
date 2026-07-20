<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Licence;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\LicenceService;
use App\Services\LicenseSigner;
use Illuminate\Http\Request;

class LicenceApiController extends Controller
{
    public function __construct(private LicenceService $licences)
    {
    }

    public function index(Request $request)
    {
        $q = Licence::with(['tenant:id,company_name', 'plan:id,code,name'])->withCount('activeDevices');

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($k = $request->query('kind')) {
            $q->where('kind', $k);
        }
        if ($search = $request->query('q')) {
            $q->where(fn ($w) => $w->where('key', 'like', "%$search%")
                ->orWhereHas('tenant', fn ($t) => $t->where('company_name', 'like', "%$search%")));
        }

        return response()->json($q->latest()->paginate(25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'plan_code' => ['required', 'exists:plans,code'],
            'kind' => ['required', 'in:trial,subscription,perpetual'],
            'billing' => ['required', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment' => ['required', 'in:client_hosted,cloud'],
            'device_limit' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $licence = $this->licences->issue(
            Tenant::findOrFail($data['tenant_id']),
            Plan::where('code', $data['plan_code'])->firstOrFail(),
            $data
        );

        AuditLog::write('licence.issued', $licence, ['key' => $licence->key]);

        return response()->json($licence->load('plan:id,code,name'), 201);
    }

    public function action(Request $request, Licence $licence)
    {
        $action = $request->validate(['action' => ['required', 'in:renew,renew_amc,suspend,resume,revoke']])['action'];

        match ($action) {
            'renew' => $this->licences->renew($licence),
            'renew_amc' => $this->licences->renewAmc($licence),
            'suspend' => $this->licences->suspend($licence),
            'resume' => $this->licences->resume($licence),
            'revoke' => $this->licences->revoke($licence),
        };

        AuditLog::write("licence.$action", $licence, ['key' => $licence->key]);

        return response()->json($licence->fresh());
    }

    /**
     * POST /admin/api/licences/{licence}/license-file
     * Generate a signed, offline node-locked license.lic for this licence (EPT-29).
     * Returns the token + filename for the browser to download.
     */
    public function licenseFile(Request $request, Licence $licence, LicenseSigner $signer)
    {
        if (! $signer->available()) {
            return response()->json([
                'error' => 'Licence signing key not set up on this server. Run:  php artisan smartept:make-keys',
            ], 422);
        }

        $data = $request->validate([
            'fingerprint' => ['nullable', 'string', 'max:190'],
        ]);

        $token = $signer->sign($licence, $data['fingerprint'] ?? null);
        AuditLog::write('licence.file_issued', $licence, [
            'key' => $licence->key,
            'locked' => ! empty($data['fingerprint']),
        ]);

        return response()->json([
            'filename' => $signer->filename($licence),
            'token' => $token,
            'locked' => ! empty($data['fingerprint']),
        ]);
    }

    public function updateLimit(Request $request, Licence $licence)
    {
        $data = $request->validate(['device_limit' => ['required', 'integer', 'min:1', 'max:100000']]);

        $active = $licence->activeDevices()->count();
        if ($data['device_limit'] < $active) {
            return response()->json([
                'error' => "Cannot set limit below currently active devices ($active). Deactivate devices first.",
            ], 422);
        }

        $licence->update($data);
        AuditLog::write('licence.limit_changed', $licence, $data);

        return response()->json($licence->fresh());
    }

    public function deactivateDevice(Request $request, Licence $licence)
    {
        $uid = $request->validate(['device_uid' => ['required', 'string']])['device_uid'];

        if (! $this->licences->deactivateDevice($licence, $uid)) {
            return response()->json(['error' => 'Device not found or already deactivated'], 404);
        }

        AuditLog::write('licence.device_deactivated', $licence, ['device_uid' => $uid]);

        return response()->json(['ok' => true]);
    }
}
