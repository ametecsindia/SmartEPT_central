<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\BillingService;
use App\Services\PricingService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROGRESSIVE / PROPORTIONAL On-Premise Lifetime pricing (Ejaz, 11-Aug-2026).
 *
 * Each band's price = one-time price AT the band's max-users MILESTONE;
 * counts between milestones are interpolated; the first band is the flat
 * minimum licence package; below minimum = validation; above the last priced
 * milestone = Custom Quote — NEVER ₹0. Fully dynamic from the admin bands.
 */
class PerpetualProgressivePricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /** Ejaz's exact example configuration: 5–15 ₹15k … 151–200 ₹90k, 201+ Custom. */
    private function plan(): Plan
    {
        $plan = Plan::where('code', 'smartept')->first()
            ?? Plan::create(['code' => 'smartept', 'name' => 'SmartEPT', 'active' => true, 'sort' => 1]);

        $plan->perpetualBands()->delete();
        $i = 0;
        foreach ([[5, 15, 15000], [16, 30, 25000], [31, 50, 40000], [51, 100, 60000],
                  [101, 150, 75000], [151, 200, 90000], [201, null, null]] as [$min, $max, $price]) {
            $plan->perpetualBands()->create([
                'min_users' => $min, 'max_users' => $max, 'price_inr' => $price, 'sort' => $i++,
            ]);
        }

        return $plan->fresh('perpetualBands');
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Perp Co', 'email' => 'perp@test.in',
            'deployment' => 'client_hosted', 'state_code' => '36',
            'setup_fee_paid' => true,
        ]);
    }

    public function test_full_acceptance_matrix(): void
    {
        $plan = $this->plan();
        $svc = app(PricingService::class);

        $expected = [
            5 => 15000, 10 => 15000, 15 => 15000,          // first band = flat minimum package
            16 => 15667,                                    // 15000 + 1×(10000/15), one final round
            30 => 25000, 31 => 25750, 50 => 40000,
            51 => 40400, 100 => 60000, 101 => 60300,
            150 => 75000, 151 => 75300, 152 => 75600,
            160 => 78000, 175 => 82500, 190 => 87000,
            199 => 89700, 200 => 90000,
        ];

        foreach ($expected as $users => $price) {
            $calc = $svc->calculateLifetimeLicencePrice($plan, $users);
            $this->assertFalse($calc['custom'], "$users users must be auto-priced");
            $this->assertFalse($calc['below_min'], "$users users is above the minimum");
            $this->assertSame($price, $calc['price'], "$users users must cost ₹$price");
        }

        // Structured data for 151: milestones + ₹300/additional-user rate.
        $calc = $svc->calculateLifetimeLicencePrice($plan, 151);
        $this->assertSame(150, $calc['previous_milestone']['users']);
        $this->assertSame(75000, $calc['previous_milestone']['price']);
        $this->assertSame(200, $calc['next_milestone']['users']);
        $this->assertSame(90000, $calc['next_milestone']['price']);
        $this->assertSame(300.0, $calc['per_user_rate']);
    }

    public function test_custom_quote_above_last_milestone_never_zero(): void
    {
        $plan = $this->plan();
        $svc = app(PricingService::class);

        foreach ([201, 500, 99999] as $users) {
            $calc = $svc->calculateLifetimeLicencePrice($plan, $users);
            $this->assertTrue($calc['custom'], "$users users must be a custom quote");
            $this->assertNull($calc['price'], "$users users must never get an automatic (or ₹0) price");

            $quote = $svc->perpetualQuote($this->tenantFresh($users), $plan, $users);
            $this->assertTrue((bool) $quote['custom']);
            $this->assertSame([], $quote['lines']);
        }
    }

    public function test_below_minimum_is_validation_not_a_price(): void
    {
        $plan = $this->plan();
        $svc = app(PricingService::class);

        foreach ([1, 4] as $users) {
            $calc = $svc->calculateLifetimeLicencePrice($plan, $users);
            $this->assertTrue($calc['below_min']);
            $this->assertSame(5, $calc['min_users'], 'minimum comes from configuration');
            $this->assertNull($calc['price']);
        }
    }

    public function test_zero_licence_impossible_at_order_creation(): void
    {
        $plan = $this->plan();
        $billing = app(BillingService::class);

        // Custom-quote count → order creation must throw, never a ₹0 order.
        $this->expectException(\RuntimeException::class);
        $billing->createOrder($this->tenant(), $plan, 500, ['kind' => 'perpetual']);
    }

    public function test_below_min_impossible_at_order_creation(): void
    {
        $plan = $this->plan();
        $billing = app(BillingService::class);

        $this->expectException(\RuntimeException::class);
        $billing->createOrder($this->tenant(), $plan, 3, ['kind' => 'perpetual']);
    }

    /** Super Admin re-prices milestones → maths follows automatically (nothing hard-coded). */
    public function test_fully_dynamic_after_admin_reprice(): void
    {
        $plan = $this->plan();
        $svc = app(PricingService::class);

        $plan->perpetualBands()->where('max_users', 150)->update(['price_inr' => 80000]);
        $plan->perpetualBands()->where('max_users', 200)->update(['price_inr' => 100000]);
        $plan = $plan->fresh('perpetualBands');

        $calc = $svc->calculateLifetimeLicencePrice($plan, 151);
        $this->assertSame(80400, $calc['price'], '80,000 + 1 × (20,000/50 = ₹400)');
        $this->assertSame(400.0, $calc['per_user_rate']);
        $this->assertSame(90000, $svc->calculateLifetimeLicencePrice($plan, 175)['price']);
        $this->assertSame(100000, $svc->calculateLifetimeLicencePrice($plan, 200)['price']);
    }

    /** Fractional segment rate (₹10,000 / 15 users): milestones exact, no drift. */
    public function test_no_cumulative_rounding_drift(): void
    {
        $plan = $this->plan();
        $svc = app(PricingService::class);

        for ($u = 16; $u <= 30; $u++) {
            $exact = (int) round(15000 + ($u - 15) * 10000 / 15);
            $this->assertSame($exact, $svc->calculateLifetimeLicencePrice($plan, $u)['price'], "user count $u");
        }
        $this->assertSame(25000, $svc->calculateLifetimeLicencePrice($plan, 30)['price'], 'milestone 30 must be EXACT');
    }

    /** AMC (18%) is charged on the interpolated prevailing price (Ejaz, 11-Aug-2026). */
    public function test_amc_uses_interpolated_price(): void
    {
        $plan = $this->plan();
        $svc = app(PricingService::class);

        $amc = $svc->amcQuote($this->tenant(), $plan, 175);   // 18% of ₹82,500
        $this->assertSame(14850.0, $amc['subtotal']);
    }

    /** A legacy open-ended band priced 0 must read as Custom Quote, never a free licence. */
    public function test_zero_priced_open_band_is_custom_quote(): void
    {
        $plan = $this->plan();
        $plan->perpetualBands()->delete();
        $plan->perpetualBands()->create(['min_users' => 5, 'max_users' => 15, 'price_inr' => 15000, 'sort' => 0]);
        $plan->perpetualBands()->create(['min_users' => 16, 'max_users' => null, 'price_inr' => 0, 'sort' => 1]);
        $plan = $plan->fresh('perpetualBands');

        $calc = app(PricingService::class)->calculateLifetimeLicencePrice($plan, 100);
        $this->assertTrue($calc['custom']);
        $this->assertNull($calc['price']);
    }

    /** Band-config validation: overlaps, gaps, decreasing prices, open band placement. */
    public function test_band_configuration_validation(): void
    {
        $plan = $this->plan();
        $admin = \App\Models\AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.in',
            'password' => 'secret-123', 'role' => 'super', 'active' => 1,
        ]);

        $put = fn (array $bands) => $this->actingAs($admin, 'admin')
            ->putJson('/admin/api/plans/' . $plan->id . '/perpetual-bands', ['bands' => $bands]);

        // Overlap
        $put([
            ['min_users' => 5, 'max_users' => 15, 'price_inr' => 15000],
            ['min_users' => 10, 'max_users' => 30, 'price_inr' => 25000],
        ])->assertStatus(422);

        // Gap
        $put([
            ['min_users' => 5, 'max_users' => 15, 'price_inr' => 15000],
            ['min_users' => 20, 'max_users' => 30, 'price_inr' => 25000],
        ])->assertStatus(422);

        // Decreasing milestone price
        $put([
            ['min_users' => 5, 'max_users' => 15, 'price_inr' => 15000],
            ['min_users' => 16, 'max_users' => 30, 'price_inr' => 12000],
        ])->assertStatus(422);

        // Open-ended band not last
        $put([
            ['min_users' => 5, 'max_users' => null, 'price_inr' => null],
            ['min_users' => 16, 'max_users' => 30, 'price_inr' => 25000],
        ])->assertStatus(422);

        // Priced band without a positive price
        $put([
            ['min_users' => 5, 'max_users' => 15, 'price_inr' => 0],
        ])->assertStatus(422);

        // Valid config saves; open band stored with NULL price (Custom Quote).
        $put([
            ['min_users' => 5, 'max_users' => 15, 'price_inr' => 15000],
            ['min_users' => 16, 'max_users' => 30, 'price_inr' => 25000],
            ['min_users' => 31, 'max_users' => null, 'price_inr' => null],
        ])->assertOk();
        $this->assertNull($plan->fresh('perpetualBands')->perpetualBands->last()->price_inr);
    }

    private function tenantFresh(int $suffix): Tenant
    {
        return Tenant::create([
            'company_name' => 'Perp Co ' . $suffix, 'email' => 'perp' . $suffix . '@test.in',
            'deployment' => 'client_hosted', 'state_code' => '36',
            'setup_fee_paid' => true,
        ]);
    }
}
