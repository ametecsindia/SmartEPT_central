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
        $action = $request->validate(['action' => ['required', 'in:renew,renew_amc,suspend,resume,revoke,release_binding']])['action'];

        // History must tell the full machine story (Ejaz, 6-Aug-2026): when a
        // binding is released, record WHICH machine it was released from.
        $extra = $action === 'release_binding'
            ? ['old_machine' => $licence->server_fingerprint]
            : [];

        match ($action) {
            'renew' => $this->licences->renew($licence),
            'renew_amc' => $this->licences->renewAmc($licence),
            'suspend' => $this->licences->suspend($licence),
            'resume' => $this->licences->resume($licence),
            'revoke' => $this->licences->revoke($licence),
            // The client's server was formatted / damaged / replaced — clear the
            // binding so the NEW server can validate and bind on its first
            // phone-home. The old server stops validating.
            'release_binding' => $licence->update(['server_fingerprint' => null]),
        };

        AuditLog::write("licence.$action", $licence, ['key' => $licence->key] + $extra);

        return response()->json($licence->fresh());
    }

    /**
     * POST /admin/api/licences/{licence}/shift-machine (Ejaz, 6-Aug-2026):
     * the installed PC was damaged/formatted/replaced — SHIFT the licence to
     * the new machine ID in one step. Old and new machine IDs are recorded
     * permanently in the licence History. After this, "Licence file" already
     * carries the new machine ID for a locked .lic download.
     */
    public function shiftMachine(Request $request, Licence $licence)
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'min:6', 'max:190'],
        ], [
            'fingerprint.required' => 'Paste the NEW machine\'s fingerprint (from its SmartEPT Activation/Licence screen).',
            'fingerprint.min' => 'That does not look like a machine fingerprint — copy it exactly from the new machine\'s Licence screen.',
        ]);

        $new = trim($data['fingerprint']);
        if ($new === $licence->server_fingerprint) {
            return response()->json(['error' => 'That is already this licence\'s bound machine — nothing to shift.'], 422);
        }

        $old = $licence->server_fingerprint;
        $licence->update(['server_fingerprint' => $new, 'activated_at' => now()]);

        AuditLog::write('licence.machine_shifted', $licence, [
            'key' => $licence->key,
            'old_machine' => $old ?: '(not bound)',
            'new_machine' => $new,
        ]);

        return response()->json([
            'ok' => true,
            'licence' => $licence->fresh(),
            'message' => 'Licence shifted to the new machine. The old machine stops validating; use "Licence file" to download a .lic locked to the new machine (its ID is already remembered).',
        ]);
    }

    /**
     * GET /admin/api/licences/{licence}/history — the licence's full life story
     * (Ejaz, 6-Aug-2026): every admin action (issue, renew, edit, suspend,
     * .lic downloads, binding releases, freed seats) from the audit log, plus
     * every order/payment tied to this licence — one chronological timeline.
     */
    public function history(Licence $licence)
    {
        $events = AuditLog::with('adminUser:id,name')
            ->where('subject_type', Licence::class)
            ->where('subject_id', $licence->id)
            ->latest()->limit(300)->get()
            ->map(fn ($a) => [
                'at' => $a->created_at->toDateTimeString(),
                'kind' => 'event',
                'action' => $a->action,
                'by' => $a->adminUser?->name ?: 'System / client',
                'meta' => $a->meta ?: [],
            ]);

        $orders = \App\Models\Order::where('licence_id', $licence->id)->latest()->get()
            ->map(fn ($o) => [
                'at' => $o->created_at->toDateTimeString(),
                'kind' => 'order',
                'action' => 'order.' . $o->status,
                'by' => $o->requested_by ?: '—',
                'meta' => [
                    'number' => $o->quote_number ?: $o->number,
                    'description' => $o->description,
                    'total' => ($o->currency === 'INR' ? '₹' : '$') . number_format((float) $o->total, 2),
                    'status' => $o->status,
                    'paid_at' => optional($o->paid_at)->toDateTimeString(),
                ],
            ]);

        $born = [[
            'at' => $licence->created_at->toDateTimeString(),
            'kind' => 'event', 'action' => 'licence.created', 'by' => '—',
            'meta' => ['key' => $licence->key, 'kind' => $licence->kind, 'devices' => $licence->device_limit],
        ]];

        $timeline = collect($born)->merge($events)->merge($orders)
            ->sortByDesc('at')->values();

        return response()->json([
            'licence' => $licence->only(['id', 'key', 'kind', 'status']),
            'timeline' => $timeline,
        ]);
    }

    /**
     * GET /admin/api/licences/{licence}/devices — every device seat on this
     * licence, so the admin can free the seat of a formatted/damaged/replaced
     * PC (the replacement then takes the free seat).
     */
    public function devices(Licence $licence)
    {
        return response()->json([
            'licence' => $licence->only(['id', 'key', 'device_limit']),
            'active' => $licence->activeDevices()->count(),
            'devices' => $licence->devices()->orderByDesc('status')->orderBy('hostname')->get()
                ->map(fn ($d) => [
                    'device_uid' => $d->device_uid,
                    'hostname' => $d->hostname,
                    'status' => $d->status,
                    'activated_at' => optional($d->activated_at)->toDateString(),
                    'deactivated_at' => optional($d->deactivated_at)->toDateString(),
                ]),
        ]);
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

        // Ejaz, 6-Aug-2026: remember the fingerprint used, so "Licence file" can
        // RE-DOWNLOAD later without retyping it (a fresh signed file with the
        // same lock is functionally identical to the original).
        $fp = trim((string) ($data['fingerprint'] ?? ''));
        if ($fp !== '' && $fp !== $licence->server_fingerprint) {
            $licence->update(['server_fingerprint' => $fp]);
        }

        $token = $signer->sign($licence, $fp !== '' ? $fp : null);
        AuditLog::write('licence.file_issued', $licence, [
            'key' => $licence->key,
            'locked' => $fp !== '',
        ]);

        return response()->json([
            'filename' => $signer->filename($licence),
            'token' => $token,
            'locked' => $fp !== '',
        ]);
    }

    /**
     * POST /admin/api/licences/{licence}/upgrade-order (12-Aug-2026)
     * The BILLED upgrade — the same engines the client portal uses, so an
     * admin-raised upgrade carries the full GST paper trail instead of a silent
     * limit edit. Cloud subscription → pro-rata difference for the remaining
     * days; Perpetual → one-time progressive lifetime price difference.
     * The order is payable (pay link) or settleable via Billing → Record payment.
     */
    public function upgradeOrder(Request $request, Licence $licence, \App\Services\BillingService $billing)
    {
        $data = $request->validate([
            'devices' => ['required', 'integer', 'min:2', 'max:100000'],
        ]);

        try {
            $order = $licence->kind === 'perpetual'
                ? $billing->createPerpetualUpgradeOrder($licence, (int) $data['devices'])
                : $billing->createUpgradeOrder($licence, (int) $data['devices']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        AuditLog::write('licence.upgrade_order', $licence, [
            'order' => $order->number,
            'from' => $licence->device_limit,
            'to' => (int) $data['devices'],
            'total' => $order->total,
            'kind' => $licence->kind,
        ]);

        return response()->json([
            'order' => $order->only(['id', 'number', 'description', 'subtotal', 'tax_amount', 'total', 'currency', 'status']),
            'pay_url' => url('/pay/' . $order->number . '/' . \App\Http\Controllers\CheckoutController::token($order)),
        ], 201);
    }

    /**
     * PUT /admin/api/licences/{licence}
     * Edit a licence — correct the expiry date, device limit, plan, kind, billing or
     * deployment (e.g. a wrong date entered by mistake). Also used by "Renew" to set
     * a chosen expiry. Setting a future expiry on an expired licence re-activates it.
     */
    public function update(Request $request, Licence $licence)
    {
        $data = $request->validate([
            'expires_at'   => ['nullable', 'date'],
            'device_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // 12-Aug: scheduled downgrade-at-renewal (null clears the schedule).
            'renewal_device_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'kind'         => ['nullable', 'in:trial,subscription,perpetual'],
            'billing'      => ['nullable', 'in:annual,half_yearly,quarterly,monthly'],
            'deployment'   => ['nullable', 'in:client_hosted,cloud'],
            'plan_code'    => ['nullable', 'exists:plans,code'],
        ]);

        if (array_key_exists('device_limit', $data) && $data['device_limit'] !== null) {
            $active = $licence->activeDevices()->count();
            if ($data['device_limit'] < $active) {
                return response()->json([
                    'error' => "Cannot set the device limit below the currently active devices ($active). Deactivate devices first.",
                ], 422);
            }
        }

        $update = [];
        foreach (['device_limit', 'kind', 'billing', 'deployment'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null) {
                $update[$f] = $data[$f];
            }
        }
        // expires_at is sent on every save: a date sets it, blank clears it (perpetual).
        if ($request->has('expires_at')) {
            $update['expires_at'] = $data['expires_at'] ?: null;
        }
        // 12-Aug: scheduled reduction — sent on save; a number schedules, blank clears.
        // Guarded like device_limit: never below the seats currently in use.
        if ($request->has('renewal_device_limit')) {
            $sched = $data['renewal_device_limit'] ?? null;
            if ($sched !== null && $sched < $licence->activeDevices()->count()) {
                return response()->json([
                    'error' => 'Scheduled reduction is below the devices currently in use — free seats first.',
                ], 422);
            }
            $update['renewal_device_limit'] = $sched;
        }
        if (! empty($data['plan_code'])) {
            $update['plan_id'] = Plan::where('code', $data['plan_code'])->value('id');
        }

        // A future expiry re-activates an expired/lapsed licence.
        if (! empty($update['expires_at'])
            && in_array($licence->status, ['expired', 'suspended'], true)
            && \Illuminate\Support\Carbon::parse($update['expires_at'])->endOfDay()->isFuture()) {
            $update['status'] = 'active';
        }

        $licence->update($update);
        AuditLog::write('licence.edited', $licence, $update);

        return response()->json($licence->fresh()->load('plan:id,code,name'));
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
