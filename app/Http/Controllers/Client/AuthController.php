<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BillingService;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /client authentication: OTP-verified self-service signup (auto trial
 * provisioning), email+password login, and OTP password reset.
 */
class AuthController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function showAuth()
    {
        return auth('client')->check() ? redirect('/client') : view('client.auth');
    }

    // ---------- Signup (step 1: send OTP) ----------

    public function signupRequestOtp(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        if (TenantUser::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'This email already has a SmartEPT account. Please sign in instead.'], 422);
        }

        $code = $this->otp->issue($data['email'], 'signup', $data['phone'] ?? null);

        return response()->json([
            'ok' => true,
            'message' => 'We emailed a 6-digit code to ' . $data['email'] . '. Enter it below to start your trial.',
            // TEST MODE convenience only (APP_DEBUG=true on Laragon). Never shown in production.
            'demo_otp' => config('app.debug') ? $code : null,
        ]);
    }

    // ---------- Signup (step 2: verify OTP → tenant + trial) ----------

    public function signupVerify(Request $request, BillingService $billing)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:190'],
            'contact_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'otp' => ['required', 'digits:6'],
            // GST profile (master prompt §6): STATE IS REQUIRED — it decides
            // whether your invoice shows CGST+SGST or IGST. GSTIN stays optional
            // but must be shaped right and must match the chosen state.
            'state_code' => ['required', 'string', 'size:2',
                'in:' . implode(',', array_keys(\App\Support\IndianStates::MAP))],
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[0-9A-Z]{13}$/i'],
            'device_estimate' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'terms_accepted' => ['accepted'],
        ], [
            'state_code.required' => 'Please pick your state — it decides how GST appears on your invoice (CGST+SGST for Telangana, IGST for other states).',
            'state_code.in' => 'Please pick your state from the list — it decides how GST appears on your invoice.',
            'gstin.regex' => 'That GSTIN does not look right — it is 15 characters, starting with your 2-digit state code (e.g. 36AAHCT0971F1ZB).',
            'terms_accepted.accepted' => 'Please tick the box agreeing to our Terms and Refund policy to continue.',
        ]);

        // The first two GSTIN digits ARE the state code — a mismatch would put
        // the wrong tax split on a legal document, so we stop it here, kindly.
        if (! empty($data['gstin']) && substr(strtoupper($data['gstin']), 0, 2) !== $data['state_code']) {
            return response()->json(['error' => 'Your GSTIN starts with "' . substr(strtoupper($data['gstin']), 0, 2)
                . '" but you picked state ' . $data['state_code'] . ' ('
                . (\App\Support\IndianStates::name($data['state_code']) ?: 'unknown')
                . '). The first two digits of a GSTIN are always the state code — please match them so your tax invoice is correct.'], 422);
        }

        if (TenantUser::where('email', $data['email'])->exists()) {
            return response()->json(['error' => 'This email already has a SmartEPT account. Please sign in instead.'], 422);
        }

        if (! $this->otp->verify($data['email'], 'signup', $data['otp'])) {
            return response()->json(['error' => 'That code is wrong or expired. Please request a fresh code.'], 422);
        }

        $user = DB::transaction(function () use ($data, $billing) {
            $tenant = Tenant::create([
                'company_name' => $data['company_name'],
                'contact_name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'deployment' => 'client_hosted',
                'status' => 'trial',
                'state_code' => $data['state_code'],
                'gstin' => ! empty($data['gstin']) ? strtoupper($data['gstin']) : null,
                'terms_accepted_at' => now(), // timestamped consent (master prompt §6)
            ]);

            $billing->provisionTrial($tenant);

            return TenantUser::create([
                'tenant_id' => $tenant->id,
                'name' => $data['contact_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => 'owner',
                'email_verified_at' => now(),
            ]);
        });

        Auth::guard('client')->login($user, true);
        $request->session()->regenerate();
        $user->update(['last_login_at' => now()]);
        AuditLog::write('client.signup', $user->tenant, ['email' => $user->email]);

        // 1.0 Interakt welcome — fire-and-forget; only sends when Interakt is
        // configured AND a 'welcome' template is approved. WaService never throws.
        if (! empty($data['phone'])) {
            \App\Services\WaService::sendTemplate([
                'mobile' => $data['phone'],
                'purpose' => 'welcome',
                'bodyValues' => [
                    $data['contact_name'] ?: $data['company_name'],
                    $data['company_name'],
                    url('/client'),
                ],
                'kind' => 'welcome',
            ]);
        }

        return response()->json(['ok' => true, 'redirect' => '/client']);
    }

    // ---------- Login / logout ----------

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::guard('client')->attempt($credentials + ['active' => 1], true)) {
            return response()->json(['error' => 'Invalid email or password.'], 422);
        }

        $request->session()->regenerate();
        auth('client')->user()->update(['last_login_at' => now()]);

        return response()->json(['ok' => true, 'redirect' => '/client']);
    }

    public function logout(Request $request)
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/client/login');
    }

    // ---------- Forgot password (OTP by email) ----------

    public function forgotRequestOtp(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // Send only when the account exists, but answer the same either way
        // so the endpoint cannot be used to probe which emails are customers.
        $demo = null;
        if (TenantUser::where('email', $data['email'])->exists()) {
            $code = $this->otp->issue($data['email'], 'reset');
            $demo = config('app.debug') ? $code : null;
        }

        return response()->json([
            'ok' => true,
            'message' => 'If that email has a SmartEPT account, a reset code is on its way.',
            'demo_otp' => $demo,
        ]);
    }

    public function forgotReset(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = TenantUser::where('email', $data['email'])->first();

        if (! $user || ! $this->otp->verify($data['email'], 'reset', $data['otp'])) {
            return response()->json(['error' => 'That code is wrong or expired. Please request a fresh code.'], 422);
        }

        $user->update(['password' => $data['password']]);
        AuditLog::write('client.password_reset', $user->tenant, ['email' => $user->email]);

        return response()->json(['ok' => true, 'message' => 'Password updated — please sign in.']);
    }
}
