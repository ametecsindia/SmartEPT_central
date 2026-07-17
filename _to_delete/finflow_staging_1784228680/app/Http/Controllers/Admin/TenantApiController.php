<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Http\Request;

class TenantApiController extends Controller
{
    public function index(Request $request)
    {
        $q = Tenant::withCount(['licences'])->with('activeLicence.plan:id,code,name');

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($search = $request->query('q')) {
            $q->where(fn ($w) => $w->where('company_name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%"));
        }

        return response()->json($q->latest()->paginate(25));
    }

    public function show(Tenant $tenant)
    {
        return response()->json($tenant->load([
            'licences.plan:id,code,name',
            'licences.devices',
            'orders' => fn ($q) => $q->latest()->take(20),
            'invoices' => fn ($q) => $q->latest()->take(20),
            'storageUsage' => fn ($q) => $q->latest('date')->take(31),
        ]));
    }

    public function store(Request $request, BillingService $billing)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/i'],
            // GST billing profile (Release-1): state code decides CGST/SGST vs IGST.
            'state_code' => ['nullable', 'string', 'size:2', 'in:' . implode(',', array_keys(\App\Support\IndianStates::MAP))],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'in:INR,USD'],
            'deployment' => ['required', 'in:client_hosted,cloud'],
            'console_url' => ['nullable', 'url', 'max:255'],
            'ecosystem_customer' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'start_trial' => ['boolean'],
        ]);

        // GSTIN ↔ state cross-check (blueprint §2): the first two GSTIN digits
        // are the state code — stop mismatches before they reach a tax document.
        if (! empty($data['gstin'])) {
            $data['gstin'] = strtoupper($data['gstin']);
            $given = $data['state_code'] ?? null;
            if ($given && substr($data['gstin'], 0, 2) !== $given) {
                return response()->json(['error' => 'GSTIN starts with "' . substr($data['gstin'], 0, 2)
                    . '" but the state code entered is ' . $given
                    . ' — the first two GSTIN digits are always the state code. Please match them.'], 422);
            }
            $data['state_code'] = $given ?: substr($data['gstin'], 0, 2);
        }

        $startTrial = (bool) ($data['start_trial'] ?? false);
        unset($data['start_trial']);

        $tenant = Tenant::create($data + ['status' => $startTrial ? 'trial' : 'active']);

        if ($startTrial) {
            $billing->provisionTrial($tenant);
        }

        // Master prompt §11: EVERY client gets a /client portal owner login.
        // Temp password is a BACKUP only — first login forces the in-app
        // create-your-own-password screen (must_set_password).
        $tempPassword = null;
        if (! \App\Models\TenantUser::where('email', $tenant->email)->exists()) {
            $tempPassword = \Illuminate\Support\Str::password(10);
            \App\Models\TenantUser::create([
                'tenant_id' => $tenant->id,
                'name' => $tenant->contact_name ?: $tenant->company_name,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'password' => $tempPassword,
                'role' => 'owner',
                'active' => 1,
                'must_set_password' => true,
                'email_verified_at' => now(),
            ]);

            app(\App\Services\MailService::class)->send(
                $tenant->email,
                'Welcome to SmartEPT — your client portal login',
                "Hello {$tenant->company_name},\n\n"
                . "Your SmartEPT client portal is ready. Sign in to manage your licence,\n"
                . "invoices, renewals and downloads:\n\n"
                . 'Portal   : ' . url('/client/login') . "\n"
                . "Email    : {$tenant->email}\n"
                . "Temporary password: {$tempPassword}\n\n"
                . "For your security you will be asked to create your own password the\n"
                . 'first time you sign in.'
                . \App\Services\MailService::signature()
            );
        }

        AuditLog::write('tenant.created', $tenant);

        // Tenant fields stay top-level (existing console + tests read them);
        // the one-time temp password rides along for the admin to hand over.
        return response()->json(
            $tenant->fresh()->toArray() + ($tempPassword ? ['portal_temp_password' => $tempPassword] : []),
            201
        );
    }

    public function update(Request $request, Tenant $tenant)
    {
        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:190'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'gstin' => ['nullable', 'string', 'max:15'],
            // GST billing profile (Release-1): state code decides CGST/SGST vs IGST.
            'state_code' => ['nullable', 'string', 'size:2', 'in:' . implode(',', array_keys(\App\Support\IndianStates::MAP))],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'string'],
            'currency' => ['sometimes', 'in:INR,USD'],
            'deployment' => ['sometimes', 'in:client_hosted,cloud'],
            'console_url' => ['nullable', 'url', 'max:255'],
            'status' => ['sometimes', 'in:trial,active,suspended,expired,churned'],
            'ecosystem_customer' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $tenant->update($data);
        AuditLog::write('tenant.updated', $tenant, ['fields' => array_keys($data)]);

        return response()->json($tenant->fresh());
    }
}
