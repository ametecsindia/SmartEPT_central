<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Lead;
use Illuminate\Http\Request;

/**
 * R3-7: lead pipeline for /admin (SmartPRS /super parity).
 * Public capture posts arrive via Api\PublicController::lead().
 */
class LeadApiController extends Controller
{
    public function index(Request $request)
    {
        $leads = Lead::query()
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->q, fn ($q, $v) => $q->where(function ($qq) use ($v) {
                $qq->where('name', 'like', "%{$v}%")
                    ->orWhere('company', 'like', "%{$v}%")
                    ->orWhere('email', 'like', "%{$v}%")
                    ->orWhere('phone', 'like', "%{$v}%");
            }))
            ->orderByRaw("case when follow_up_at is not null and follow_up_at <= now() then 0 else 1 end")
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json($leads);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:190'],
            'devices_interested' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_at' => ['nullable', 'date'],
        ]);

        $lead = Lead::create($data + ['source' => $data['source'] ?? 'manual']);

        AuditLog::write('lead.created', $lead, ['name' => $lead->name]);

        return response()->json(['data' => $lead], 201);
    }

    public function update(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:' . implode(',', Lead::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'follow_up_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'string', 'max:190'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'name' => ['sometimes', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'devices_interested' => ['nullable', 'integer', 'min:1'],
        ]);

        $lead->update($data);

        AuditLog::write('lead.updated', $lead, $data);

        return response()->json(['data' => $lead->fresh()]);
    }
}
