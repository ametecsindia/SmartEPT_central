<?php

namespace Tests\Feature;

use App\Models\Licence;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Services\LicenceService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licence change flows (Ejaz, 12-Aug-2026):
 *  - PERPETUAL upgrade = one-time difference of interpolated lifetime prices
 *    (once purchased, seats only go UP — reductions rejected).
 *  - Cloud DOWNGRADE-AT-RENEWAL: scheduled reduction bills the reduced size
 *    and applies + clears on provisioning; mid-period reductions stay blocked.
 */
class LicenceChangeFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /** Ejaz's example bands: milestones 15→₹15k, 30→₹25k, 50→₹40k, 100→₹60k. */
    private function plan(): Plan
    {
        $plan = Plan::where('code', 'smartept')->first()
            ?? Plan::create(['code' => 'smartept', 'name' => 'SmartEPT', 'active' => true, 'sort' => 1]);

        $plan->perpetualBands()->delete();
        $i = 0;
        foreach ([[5, 15, 15000], [16, 30, 25000], [31, 50, 40000], [51, 100, 60000], [101, null, null]] as [$min, $max, $price]) {
            $plan->perpetualBands()->create([
                'min_users' => $min, 'max_users' => $max, 'price_inr' => $price, 'sort' => $i++,
            ]);
        }

        return $plan->fresh('perpetualBands');
    }

    private function tenant(string $deployment = 'client_hosted'): Tenant
    {
        return Tenant::create([
            'company_name' => 'Change Co', 'email' => 'change@test.in',
            'deployment' => $deployment, 'state_code' => '36', 'setup_fee_paid' => true,
        ]);
    }

    public function test_perpetual_upgrade_charges_the_interpolated_difference(): void
    {
        $plan = $this->plan();
        $licences = app(LicenceService::class);
        $billing = app(BillingService::class);

        $licence = $licences->issue($this->tenant(), $plan, [
            'kind' => 'perpetual', 'deployment' => 'client_hosted', 'device_limit' => 15,
        ]);
        $licence->update(['status' => 'active']);

        // 15 users = ₹15,000 milestone; 30 users = ₹25,000 milestone → difference ₹10,000.
        $order = $billing->createPerpetualUpgradeOrder($licence->fresh(), 30);

        $this->assertSame(10000.0, (float) $order->subtotal);
        $this->assertSame(30, (int) $order->meta['upgrade_new_limit']);
        $this->assertTrue((bool) $order->meta['perpetual_upgrade']);
        // 18% GST on the difference.
        $this->assertSame(1800.0, (float) $order->tax_amount);

        // Payment applies the new limit through the golden path.
        $billing->markPaid($order, ['method' => 'test']);
        $this->assertSame(30, (int) $licence->fresh()->device_limit);
    }

    public function test_perpetual_reduction_and_oversize_are_rejected(): void
    {
        $plan = $this->plan();
        $licence = app(LicenceService::class)->issue($this->tenant(), $plan, [
            'kind' => 'perpetual', 'deployment' => 'client_hosted', 'device_limit' => 30,
        ]);
        $licence->update(['status' => 'active']);
        $billing = app(BillingService::class);

        // Once purchased, never reduced.
        $this->expectException(\RuntimeException::class);
        $billing->createPerpetualUpgradeOrder($licence->fresh(), 20);
    }

    public function test_perpetual_upgrade_above_priced_bands_needs_custom_quote(): void
    {
        $plan = $this->plan();
        $licence = app(LicenceService::class)->issue($this->tenant(), $plan, [
            'kind' => 'perpetual', 'deployment' => 'client_hosted', 'device_limit' => 30,
        ]);
        $licence->update(['status' => 'active']);

        $this->expectException(\RuntimeException::class); // 150 > last priced milestone (100)
        app(BillingService::class)->createPerpetualUpgradeOrder($licence->fresh(), 150);
    }

    public function test_scheduled_reduction_bills_reduced_size_and_applies_on_renewal(): void
    {
        $plan = $this->plan();
        $licences = app(LicenceService::class);
        $billing = app(BillingService::class);

        $licence = $licences->issue($this->tenant('cloud'), $plan, [
            'kind' => 'subscription', 'billing' => 'annual', 'deployment' => 'cloud', 'device_limit' => 40,
        ]);
        $licence->update(['status' => 'active', 'renewal_device_limit' => 25]);

        $order = $billing->createRenewalOrder($licence->fresh());

        // Billed at the REDUCED size, and the meta instructs provisioning to apply it.
        $this->assertSame(25, (int) $order->meta['devices']);
        $this->assertSame(25, (int) $order->meta['apply_device_limit']);
        $this->assertStringContainsString('25 devices', $order->description);
        $this->assertStringContainsString('scheduled reduction', $order->description);

        $billing->markPaid($order, ['method' => 'test']);

        $fresh = $licence->fresh();
        $this->assertSame(25, (int) $fresh->device_limit);       // applied
        $this->assertNull($fresh->renewal_device_limit);          // schedule consumed
    }

    public function test_renewal_without_schedule_is_unchanged(): void
    {
        $plan = $this->plan();
        $billing = app(BillingService::class);
        $licence = app(LicenceService::class)->issue($this->tenant('cloud'), $plan, [
            'kind' => 'subscription', 'billing' => 'annual', 'deployment' => 'cloud', 'device_limit' => 40,
        ]);
        $licence->update(['status' => 'active']);

        $order = $billing->createRenewalOrder($licence->fresh());

        $this->assertSame(40, (int) $order->meta['devices']);
        $this->assertNull($order->meta['apply_device_limit']);

        $billing->markPaid($order, ['method' => 'test']);
        $this->assertSame(40, (int) $licence->fresh()->device_limit);
    }
}
