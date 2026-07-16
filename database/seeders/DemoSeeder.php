<?php

namespace Database\Seeders;

use App\Models\StorageUsage;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Services\LicenceService;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA — illustrative only. Safe to delete in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Tenant::count() > 0) {
            return; // never duplicate demo data
        }

        $billing = app(BillingService::class);
        $licences = app(LicenceService::class);
        $pro = Plan::where('code', 'professional')->first();
        $ent = Plan::where('code', 'enterprise')->first();

        // 1. Client-hosted paid customer (ecosystem).
        $abc = Tenant::create([
            'company_name' => 'ABC Recoveries Pvt Ltd', 'contact_name' => 'Rajesh Kumar',
            'email' => 'admin@abcrecoveries.in', 'phone' => '9848012345',
            'gstin' => '36AABCA1234F1Z5', 'address' => 'Begumpet, Hyderabad',
            'deployment' => 'client_hosted', 'status' => 'active', 'ecosystem_customer' => true,
        ]);
        $order = $billing->createOrder($abc, $pro, 50, ['kind' => 'subscription', 'billing' => 'annual']);
        $billing->markPaid($order, ['gateway' => 'manual', 'manual_method' => 'NEFT', 'manual_reference' => 'DEMO-NEFT-001']);

        // 2. Managed-cloud customer with storage usage.
        $godavari = Tenant::create([
            'company_name' => 'Godavari Finserv', 'contact_name' => 'Lakshmi Devi',
            'email' => 'it@godavarifinserv.in', 'phone' => '9848098765',
            'deployment' => 'cloud', 'status' => 'active',
        ]);
        $order2 = $billing->createOrder($godavari, $ent, 120, ['kind' => 'subscription', 'billing' => 'annual', 'deployment' => 'cloud']);
        $billing->markPaid($order2, ['gateway' => 'manual', 'manual_method' => 'UPI', 'manual_reference' => 'DEMO-UPI-002']);
        for ($i = 14; $i >= 1; $i--) {
            StorageUsage::create([
                'tenant_id' => $godavari->id,
                'date' => now()->subDays($i)->toDateString(),
                'gb_used' => 180 + $i * 2.5,
            ]);
        }

        // 3. Active trial.
        $krishna = Tenant::create([
            'company_name' => 'Krishna NBFC', 'contact_name' => 'Suresh Rao',
            'email' => 'ops@krishnanbfc.in', 'phone' => '9848055555',
            'deployment' => 'client_hosted', 'status' => 'trial',
        ]);
        $billing->provisionTrial($krishna);

        // Demo devices on ABC's licence.
        $lic = $abc->licences()->first();
        foreach (['WS-ACC-01', 'WS-ACC-02', 'WS-CALL-01', 'WS-CALL-02', 'WS-CALL-03'] as $i => $uid) {
            $licences->activateDevice($lic, 'DEMO-' . $uid, $uid);
        }
    }
}
