<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\WaTemplate;
use App\Services\WaService;
use Illuminate\Http\Request;

/**
 * WhatsApp TEMPLATE manager (Central). Interakt's API can only SEND approved
 * templates — they are created/approved in the Interakt dashboard. This module is
 * the registry + workflow tracker: draft → (submit in Interakt) → a successful
 * [Send test] PROVES approval → approved. The live flows (lead/welcome/payment/
 * renewal/otp) resolve their template name from the approved row for that purpose.
 */
class WaTemplateController extends Controller
{
    public const PURPOSES = [
        'welcome' => 'Signup / trial welcome',
        'payment' => 'Payment confirmation',
        'renewal' => 'Renewal reminder',
        'lead' => 'Website lead — thank-you',
        'otp' => 'OTP / verification (Authentication)',
        'custom' => 'Custom',
    ];

    public function index()
    {
        return response()->json([
            'data' => WaTemplate::orderBy('purpose')->orderBy('id')->get(),
            'purposes' => self::PURPOSES,
            'configured' => (bool) WaService::config(),
        ]);
    }

    public function store(Request $request)
    {
        $t = WaTemplate::create($this->validated($request));
        AuditLog::write('wa_template.created', $t, ['purpose' => $t->purpose, 'name' => $t->name]);

        return response()->json(['data' => $t], 201);
    }

    public function update(Request $request, WaTemplate $waTemplate)
    {
        $waTemplate->update($this->validated($request));
        AuditLog::write('wa_template.updated', $waTemplate, ['name' => $waTemplate->name]);

        return response()->json(['data' => $waTemplate]);
    }

    public function destroy(WaTemplate $waTemplate)
    {
        $id = $waTemplate->id;
        $waTemplate->delete();
        AuditLog::write('wa_template.deleted', null, ['id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** POST test — a successful send proves the template is approved in Interakt. */
    public function test(Request $request, WaTemplate $waTemplate)
    {
        $data = $request->validate(['mobile' => ['required', 'string', 'max:20']]);
        $vals = array_values(array_filter(array_map('trim', explode(',', (string) $waTemplate->sample_values)), fn ($v) => $v !== ''));

        $ok = WaService::sendTemplate([
            'mobile' => $data['mobile'],
            'template' => $waTemplate->name,
            'lang' => $waTemplate->language ?: 'en',
            'bodyValues' => $vals,
            'purpose' => $waTemplate->purpose,
            'kind' => 'test:' . $waTemplate->purpose,
        ]);

        $waTemplate->update([
            'last_test_at' => now(),
            'status' => $ok ? 'approved' : ($waTemplate->status === 'approved' ? 'approved' : 'submitted'),
            'last_error' => $ok ? null : 'Last test failed — confirm the template name matches Interakt exactly, it is approved there, and the sample values match its variables.',
        ]);
        AuditLog::write('wa_template.tested', $waTemplate, ['ok' => $ok]);

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Test sent — the template is live and now marked Approved.'
                : 'Test failed. Check: the name matches Interakt exactly, the template is approved there, and the sample values match its variable count.',
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'purpose' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:190'],
            'language' => ['nullable', 'string', 'max:10'],
            'category' => ['nullable', 'string', 'max:20'],
            'body' => ['nullable', 'string'],
            'sample_values' => ['nullable', 'string', 'max:1000'],
            'var_count' => ['nullable', 'integer', 'min:0', 'max:20'],
            'status' => ['nullable', 'in:draft,submitted,approved,rejected'],
        ]);
    }
}
