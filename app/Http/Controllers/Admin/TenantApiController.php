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
        $q = Tenant::withCount(['licences'])->with('activeLicence.plan:id,code,name,storage_gb');

        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }
        if ($search = $request->query('q')) {
            $q->where(fn ($w) => $w->where('company_name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%"));
        }

        $page = $q->latest()->paginate(25);
        $page->getCollection()->transform(function ($t) {
            $t->setAttribute('storage', $t->storageStatus());

            return $t;
        });

        return response()->json($page);
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
            // Branded console URL slug (admin.smartept.com/<slug>). Blank = auto from name.
            'console_slug' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:tenants,console_slug'],
            'ecosystem_customer' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'start_trial' => ['boolean'],
        ]);

        // Auto-suggest a clean slug from the company name when none was entered.
        if (empty($data['console_slug'])) {
            $data['console_slug'] = $this->autoSlug($data['company_name']);
        }

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

        // Stand up the hosted console immediately for cloud tenants (idempotent).
        if ($tenant->deployment === 'cloud') {
            app(\App\Services\ProductProvisioner::class)->ensureFor($tenant->fresh());
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
            'console_slug' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'unique:tenants,console_slug,' . $tenant->id],
            'status' => ['sometimes', 'in:trial,active,suspended,expired,churned'],
            'ecosystem_customer' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $tenant->update($data);

        // Hard cut-off: a suspend/enable propagates to the hosted console (cloud tenants).
        if (array_key_exists('status', $data)) {
            app(\App\Services\ProductProvisioner::class)->setStatus($tenant->fresh(), $data['status']);
        }

        // Push the branded slug (and console URL) to the hosted product now — so a
        // slug edit takes effect on Save, without waiting for a payment. Idempotent.
        if ($tenant->fresh()->deployment === 'cloud') {
            app(\App\Services\ProductProvisioner::class)->ensureFor($tenant->fresh());
        }

        AuditLog::write('tenant.updated', $tenant, ['fields' => array_keys($data)]);

        return response()->json($tenant->fresh());
    }

    /** A clean, unique console slug from the company name (admin.smartept.com/<slug>). */
    private function autoSlug(string $name): string
    {
        $base = substr(trim(\Illuminate\Support\Str::slug($name, ''), '-'), 0, 38) ?: 'client';
        $slug = $base;
        $i = 1;
        while (Tenant::where('console_slug', $slug)->exists()) {
            $slug = substr($base, 0, 34) . '-' . $i++;
        }

        return $slug;
    }
}
