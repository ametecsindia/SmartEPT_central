<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Licence;
use App\Models\Order;
use App\Models\Tenant;

class DashboardApiController extends Controller
{
    public function stats()
    {
        $mrr = 0.0;
        foreach (Licence::with('plan')->where('status', 'active')->where('kind', 'subscription')->get() as $lic) {
            $months = $lic->billing === 'annual' ? 12 : 1;
            $orders = Order::where('licence_id', $lic->id)->where('status', 'paid')->latest('paid_at')->first();
            if ($orders) {
                $licenceLine = collect($orders->line_items)->firstWhere('type', 'licence');
                if ($licenceLine) {
                    $mrr += $licenceLine['amount'] / $months;
                }
            }
        }

        return response()->json([
            'tenants_total' => Tenant::count(),
            'tenants_active' => Tenant::where('status', 'active')->count(),
            'tenants_trial' => Tenant::where('status', 'trial')->count(),
            'trials_expiring_7d' => Tenant::where('status', 'trial')
                ->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->count(),
            'licences_active' => Licence::where('status', 'active')->count(),
            'devices_active' => \App\Models\LicenceDevice::where('status', 'active')->count(),
            'mrr_estimate' => round($mrr, 2),
            'revenue_this_month' => (float) Order::where('status', 'paid')
                ->whereMonth('paid_at', now()->month)->whereYear('paid_at', now()->year)->sum('total'),
            'orders_pending' => Order::where('status', 'created')->count(),
            'recent_orders' => Order::with('tenant:id,company_name')->latest()->take(8)
                ->get(['id', 'number', 'tenant_id', 'description', 'total', 'currency', 'status', 'created_at']),
            'recent_activations' => Licence::with('tenant:id,company_name')->whereNotNull('activated_at')
                ->latest('activated_at')->take(8)->get(['id', 'key', 'tenant_id', 'activated_at']),
        ]);
    }
}
