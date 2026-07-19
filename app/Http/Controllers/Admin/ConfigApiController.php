<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Http\Request;

class ConfigApiController extends Controller
{
    public function plans()
    {
        return response()->json(Plan::with('volumeTiers')->orderBy('sort')->get());
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'inr_annual' => ['sometimes', 'integer', 'min:0'],
            'inr_monthly' => ['sometimes', 'integer', 'min:0'],
            'usd_annual' => ['sometimes', 'numeric', 'min:0'],
            'usd_monthly' => ['sometimes', 'numeric', 'min:0'],
            'perpetual_device_inr' => ['sometimes', 'integer', 'min:0'],
            'perpetual_server_inr' => ['sometimes', 'integer', 'min:0'],
            'min_devices' => ['sometimes', 'integer', 'min:1'],
            'storage_gb' => ['sometimes', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $plan->update($data);
        AuditLog::write('plan.updated', $plan, ['fields' => array_keys($data)]);

        return response()->json($plan->fresh('volumeTiers'));
    }

    private const EDITABLE_SETTINGS = [
        'gst_rate', 'invoice_prefix', 'quote_prefix', 'order_prefix', 'company_name', 'company_address',
        'company_gstin', 'company_phone', 'company_email', 'whatsapp_number',
        'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        'interakt_api_url', 'interakt_api_key', 'interakt_sender_number', 'interakt_waba_id', 'interakt_status',
        'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
        'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
        // Landing CMS (master prompt §5) — marketing edits at the owner's speed.
        'landing_hero_title', 'landing_hero_subtitle', 'landing_announcement',
        'landing_contact_phone', 'landing_contact_email', 'landing_testimonials',
        'sales_email',
        // Pricing, billing cycles & cloud — Central -> Settings -> Pricing & Cloud
        'pricing_annual_discount_pct', 'pricing_half_yearly_discount_pct', 'pricing_cloud_multiplier',
        'pricing_setup_base_inr', 'pricing_setup_included_devices', 'pricing_setup_per_extra_inr',
        'pricing_storage_min_gb', 'pricing_storage_min_inr', 'pricing_storage_slabs',
    ];

    private const SECRET_SETTINGS = [
        'razorpay_key_secret', 'razorpay_webhook_secret', 'stripe_secret_key', 'stripe_webhook_secret',
        'mail_password', 'interakt_api_key',
    ];

    /** Effective defaults surfaced in the Settings form when a pricing knob is unset. */
    public const PRICING_DEFAULTS = [
        'pricing_annual_discount_pct' => 25,
        'pricing_half_yearly_discount_pct' => 10,
        'pricing_cloud_multiplier' => 1.5,
        'pricing_setup_base_inr' => 5000,
        'pricing_setup_included_devices' => 25,
        'pricing_setup_per_extra_inr' => 100,
        'pricing_storage_min_gb' => 50,
        'pricing_storage_min_inr' => 150,
        'pricing_storage_slabs' => '[[1,500,3],[501,2048,2.5],[2049,null,2]]',
    ];

    public function settings()
    {
        $out = [];
        foreach (self::EDITABLE_SETTINGS as $key) {
            $value = Setting::get($key, self::PRICING_DEFAULTS[$key] ?? '');
            if (in_array($key, self::SECRET_SETTINGS) && $value !== '') {
                $value = '••••••••' . substr($value, -4);
            }
            $out[$key] = $value;
        }

        return response()->json($out);
    }

    public function updateSettings(Request $request)
    {
        if ($request->filled('pricing_storage_slabs')) {
            $slabs = json_decode((string) $request->input('pricing_storage_slabs'), true);
            $valid = is_array($slabs) && $slabs !== [];
            foreach ((array) $slabs as $row) {
                if (! is_array($row) || count($row) < 3 || ! is_numeric($row[0])
                    || ! ($row[1] === null || is_numeric($row[1])) || ! is_numeric($row[2])) {
                    $valid = false;
                    break;
                }
            }
            if (! $valid) {
                return response()->json(['message' => 'Storage slabs must be a JSON list of [from_gb, to_gb|null, rate], e.g. [[1,500,3],[501,2048,2.5],[2049,null,2]].'], 422);
            }
        }

        foreach ($request->only(self::EDITABLE_SETTINGS) as $key => $value) {
            if ($value === null || str_starts_with((string) $value, '••••')) {
                continue; // masked secrets unchanged
            }
            Setting::set($key, $value);
        }

        AuditLog::write('settings.updated', null, ['keys' => array_keys($request->all())]);

        // The public landing reads these through a 5-minute cache — bust it so
        // "save → refresh landing" shows the edit immediately.
        \Illuminate\Support\Facades\Cache::forget('public_content_v1');
        \Illuminate\Support\Facades\Cache::forget('public_plans_v2');

        return response()->json(['ok' => true]);
    }

    /** POST config/test-email — send a test using the current SMTP settings. */
    public function testEmail(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'email']]);
        $ok = app(\App\Services\MailService::class)->send(
            $data['to'],
            'SmartEPT — test email',
            "This is a test email from SmartEPT Central.\nIf you received it, your SMTP settings are working."
            . \App\Services\MailService::signature()
        );

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Test email sent to ' . $data['to'] . ' — check the inbox (and spam).'
                : 'Could not send. Check the SMTP host, port, username and password, then try again.',
        ]);
    }

    public function audit(Request $request)
    {
        $q = \App\Models\AuditLog::with('adminUser:id,name')->latest();
        if ($request->filled('action')) {
            $q->where('action', $request->query('action'));
        }

        return response()->json($q->paginate(50));
    }
}
