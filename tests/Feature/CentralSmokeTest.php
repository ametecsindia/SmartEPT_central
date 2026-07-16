<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Services\LicenceService;
use App\Services\PricingService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_setup_fee_math(): void
    {
        $p = app(PricingService::class);
        $this->assertSame(5000, $p->setupFee(10));
        $this->assertSame(5000, $p->setupFee(25));
        $this->assertSame(5100, $p->setupFee(26));
        $this->assertSame(7500, $p->setupFee(50));
        $this->assertSame(12500, $p->setupFee(100));
    }

    public function test_volume_tiers_and_cloud_multiplier(): void
    {
        $p = app(PricingService::class);
        $pro = Plan::where('code', 'professional')->first();
        $this->assertSame(59.0, $p->deviceRate($pro, 10));
        $this->assertSame(49.0, $p->deviceRate($pro, 50));
        $this->assertSame(39.0, $p->deviceRate($pro, 200));
        $this->assertSame(29.0, $p->deviceRate($pro, 400));
        $this->assertSame(24.0, $p->deviceRate($pro, 900));
        // cloud = ×1.5 rounded — framework table: 59→89 at public tier
        $this->assertSame(89.0, $p->deviceRate($pro, 10, 'annual', 'cloud'));
    }

    public function test_storage_slabs(): void
    {
        $p = app(PricingService::class);
        $this->assertSame(150.0, $p->storageMonthly(10));   // 50 GB minimum
        $this->assertSame(600.0, $p->storageMonthly(200));
        $this->assertSame(2750.0, $p->storageMonthly(1000));
    }

    public function test_golden_path_order_to_licence_to_invoice(): void
    {
        $billing = app(BillingService::class);
        $tenant = Tenant::create([
            'company_name' => 'Test Co', 'email' => 't@test.in', 'deployment' => 'client_hosted',
        ]);
        $pro = Plan::where('code', 'professional')->first();

        $order = $billing->createOrder($tenant, $pro, 50, ['kind' => 'subscription', 'billing' => 'annual']);
        // 50 × ₹49 × 12 = 29,400 + setup 7,500 = 36,900 + 18% GST = 43,542
        $this->assertEquals(36900.0, (float) $order->subtotal);
        $this->assertEquals(43542.0, (float) $order->total);

        $paid = $billing->markPaid($order, ['gateway' => 'manual', 'manual_method' => 'NEFT']);

        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->licence_id);
        $this->assertSame('active', $paid->licence->status);
        $this->assertSame(50, $paid->licence->device_limit);
        $this->assertNotNull($paid->invoice);
        // SmartPRS numbering pattern: EPT-{FY}-{MM}-{count}, count resets monthly
        $this->assertMatchesRegularExpression('/^EPT-\\d{4}-\\d{2}-\\d{2}-\\d{4}$/', $paid->invoice->number);
        $this->assertTrue($tenant->fresh()->setup_fee_paid);

        // Second order for same tenant must NOT include setup fee again.
        $order2 = $billing->createOrder($tenant->fresh(), $pro, 50, ['kind' => 'subscription', 'billing' => 'annual']);
        $this->assertEquals(29400.0, (float) $order2->subtotal);
    }

    public function test_quotation_flow_manager_requests_management_pays(): void
    {
        $billing = app(BillingService::class);
        $tenant = Tenant::create(['company_name' => 'Quote Co', 'email' => 'q@quote.in']);
        $pro = Plan::where('code', 'professional')->first();

        // Manager raises a quotation
        $q = $billing->createOrder($tenant, $pro, 25, [
            'kind' => 'subscription', 'billing' => 'annual',
            'as_quote' => true, 'requested_by' => 'Rajesh Kumar, Ops Manager',
        ]);
        $this->assertSame('quote', $q->status);
        $this->assertMatchesRegularExpression('/^EPT-Q-\\d{4}-\\d{2}-\\d{2}-\\d{4}$/', $q->quote_number);
        $this->assertSame('Rajesh Kumar, Ops Manager', $q->requested_by);

        // Management approves → payable order
        $billing->approveQuote($q);
        $this->assertSame('created', $q->fresh()->status);

        // Management pays (manual NEFT) → licence + invoice, quote number preserved
        $paid = $billing->markPaid($q->fresh(), ['gateway' => 'manual', 'manual_method' => 'NEFT']);
        $this->assertSame('paid', $paid->status);
        $this->assertNotNull($paid->licence_id);
        $this->assertNotNull($paid->quote_number);
        $this->assertNotNull($paid->invoice);
    }

    public function test_management_can_pay_quote_directly_without_approval_step(): void
    {
        $billing = app(BillingService::class);
        $tenant = Tenant::create(['company_name' => 'Direct Pay Co', 'email' => 'd@direct.in']);
        $pro = Plan::where('code', 'professional')->first();

        $q = $billing->createOrder($tenant, $pro, 10, ['as_quote' => true]);
        $paid = $billing->markPaid($q, ['gateway' => 'razorpay', 'payment_id' => 'pay_TEST123']);

        $this->assertSame('paid', $paid->status);
        $this->assertSame('active', $paid->licence->status);
    }

    public function test_licence_validation_and_device_cap(): void
    {
        $licences = app(LicenceService::class);
        $tenant = Tenant::create(['company_name' => 'Cap Co', 'email' => 'c@cap.in']);
        $lic = $licences->issue($tenant, Plan::where('code', 'core')->first(), ['device_limit' => 2]);

        $v = $licences->validate($lic->key, 'FP-1');
        $this->assertTrue($v['ok']);
        $this->assertSame('core', $v['bundle']['plan']);
        $this->assertNotEmpty($v['bundle']['signature']);

        $this->assertTrue($licences->activateDevice($lic, 'D1')['ok']);
        $this->assertTrue($licences->activateDevice($lic, 'D2')['ok']);
        $third = $licences->activateDevice($lic, 'D3');
        $this->assertFalse($third['ok']);
        $this->assertSame('device_limit_reached', $third['reason']);

        // reassignment: deactivate D1 frees a seat
        $this->assertTrue($licences->deactivateDevice($lic, 'D1'));
        $this->assertTrue($licences->activateDevice($lic, 'D3')['ok']);

        // wrong fingerprint refused
        $bad = $licences->validate($lic->key, 'FP-DIFFERENT');
        $this->assertFalse($bad['ok']);
        $this->assertSame('server_mismatch', $bad['reason']);
    }

    public function test_license_api_endpoints(): void
    {
        $licences = app(LicenceService::class);
        $tenant = Tenant::create(['company_name' => 'API Co', 'email' => 'a@api.in']);
        $lic = $licences->issue($tenant, Plan::where('code', 'professional')->first(), ['device_limit' => 5]);

        $this->postJson('/api/v1/license/validate', ['key' => $lic->key, 'fingerprint' => 'SRV-1'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('bundle.plan', 'professional');

        $this->postJson('/api/v1/license/validate', ['key' => 'SEPT-FAKE-FAKE-FAKE-0000'])
            ->assertStatus(403)->assertJsonPath('reason', 'unknown_key');

        $this->postJson('/api/v1/license/device/activate', ['key' => $lic->key, 'device_uid' => 'PC-01', 'hostname' => 'WS1'])
            ->assertOk()->assertJsonPath('devices_active', 1);
    }

    public function test_admin_auth_wall(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->getJson('/admin/api/dashboard')->assertStatus(401);
    }

    public function test_public_plans_endpoint_feeds_the_landing_page(): void
    {
        $this->getJson('/api/v1/public/plans')->assertOk()
            ->assertJsonCount(3, 'plans')
            ->assertJsonPath('cloud_multiplier', 1.5)
            ->assertJsonPath('setup.base', 5000)
            ->assertJsonPath('setup.included', 25)
            ->assertJsonPath('trial.days', 7);
    }
}
