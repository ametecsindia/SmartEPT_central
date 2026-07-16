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
        'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
        'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
    ];

    private const SECRET_SETTINGS = [
        'razorpay_key_secret', 'razorpay_webhook_secret', 'stripe_secret_key', 'stripe_webhook_secret',
    ];

    public function settings()
    {
        $out = [];
        foreach (self::EDITABLE_SETTINGS as $key) {
            $value = Setting::get($key, '');
            if (in_array($key, self::SECRET_SETTINGS) && $value !== '') {
                $value = '••••••••' . substr($value, -4);
            }
            $out[$key] = $value;
        }

        return response()->json($out);
    }

    public function updateSettings(Request $request)
    {
        foreach ($request->only(self::EDITABLE_SETTINGS) as $key => $value) {
            if ($value === null || str_starts_with((string) $value, '••••')) {
                continue; // masked secrets unchanged
            }
            Setting::set($key, $value);
        }

        AuditLog::write('settings.updated', null, ['keys' => array_keys($request->all())]);

        return response()->json(['ok' => true]);
    }

    public function audit()
    {
        return response()->json(
            \App\Models\AuditLog::with('adminUser:id,name')->latest()->paginate(50)
        );
    }
}
