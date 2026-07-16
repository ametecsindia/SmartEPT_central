<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** R3-7: coupon CRUD for /admin. Redemption is counted at payment time. */
class CouponApiController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Coupon::latest('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['code'] = strtoupper($data['code']);

        $coupon = Coupon::create($data);

        AuditLog::write('coupon.created', $coupon, ['code' => $coupon->code]);

        return response()->json(['data' => $coupon], 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $data = $this->validated($request, $coupon);

        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }

        $coupon->update($data);

        AuditLog::write('coupon.updated', $coupon, $data);

        return response()->json(['data' => $coupon->fresh()]);
    }

    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $req = $coupon ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$req, 'string', 'max:40', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:190'],
            'type' => [$req, 'in:percent,flat'],
            'value' => [$req, 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'min_devices' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
