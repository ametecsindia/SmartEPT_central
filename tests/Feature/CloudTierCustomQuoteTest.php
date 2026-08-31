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
 * Cloud above the top volume tier = CUSTOM QUOTE, never ₹0 (31-Aug-2026).
 *
 * The live plan carries an open-ended tier (501+, rate 0) as the custom-quote
 * marker — exactly like the perpetual 201+ band with a null price. deviceRate()
 * used to MATCH that tier and return a ₹0 rate, so /buy showed "₹0 payable"
 * with a live "Pay securely & activate" button for any count above 500.
 */
class CloudTierCustomQuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /** The live smartept.com tier table. */
    private function plan(): Plan
    {
        $plan = Plan::where('code', 'smartept')->first()
            ?? Plan::create(['code' => 'smartept', 'name' => 'SmartEPT', 'active' => true, 'sort' => 1]);
        $plan->inr_annual = 100;
        $plan->save();

        $plan->volumeTiers()->delete();
        $i = 0;
        foreach ([[10, 10, 100], [11, 25, 90], [26, 50, 85], [51, 150, 80],
                  [151, 250, 75], [251, 500, 70], [501, null, 0]] as [$min, $max, $rate]) {
            $plan->volumeTiers()->create([
                'min_devices' => $min, 'max_devices' => $max, 'rate_inr_annual' => $rate, 'sort' => $i++,
            ]);
        }

        return $plan->fresh('volumeTiers');
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Cloud Co', 'email' => 'cloud@test.in',
            'deployment' => 'cloud', 'state_code' => '36', 'setup_fee_paid' => true,
        ]);
    }

    public function test_top_priced_tier_is_500_not_the_open_ended_one(): void
    {
        $p = app(PricingService::class);
        $this->assertSame(500, $p->maxPricedDevices($this->plan()));
    }

    public function test_counts_inside_the_tiers_are_unchanged(): void
    {
        $plan = $this->plan();
        $p = app(PricingService::class);

        foreach ([10 => 100.0, 20 => 90.0, 40 => 85.0, 100 => 80.0,
                  200 => 75.0, 500 => 70.0] as $users => $rate) {
            $this->assertSame($rate, $p->deviceRate($plan, $users, 'annual', 'cloud', false),
                "annual rate wrong at {$users} users");
            $this->assertFalse($p->cloudIsCustom($plan, $users), "{$users} users should be auto-priced");
        }
    }

    public function test_above_the_top_tier_is_custom_and_never_zero(): void
    {
        $plan = $this->plan();
        $p = app(PricingService::class);

        foreach ([501, 600, 5000] as $users) {
            $this->assertTrue($p->cloudIsCustom($plan, $users), "{$users} users must be custom");

            // The rate itself must never come back as the open tier's 0.
            $this->assertGreaterThan(0, $p->deviceRate($plan, $users, 'annual', 'cloud', false),
                "deviceRate returned a zero rate at {$users} users");

            $quote = $p->subscriptionQuote($this->tenant(), $plan, $users, 'annual');
            $this->assertTrue($quote['custom'], "quote at {$users} users must be flagged custom");
            $this->assertSame([], $quote['lines']);
            $this->assertSame(0.0, $quote['subtotal']);
            $this->assertSame(500, $quote['max_priced_devices']);
        }
    }

    public function test_createOrder_refuses_to_mint_a_free_cloud_order(): void
    {
        $plan = $this->plan();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('custom quotation');

        app(BillingService::class)->createOrder($this->tenant(), $plan, 600, [
            'kind' => 'subscription', 'billing' => 'annual', 'include_setup' => false,
        ]);
    }

    public function test_a_normal_cloud_order_still_works(): void
    {
        $plan = $this->plan();
        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 100, [
            'kind' => 'subscription', 'billing' => 'annual', 'include_setup' => false,
        ]);

        // 100 users x Rupee 80/user/month x 12 months
        $this->assertSame(96000.0, (float) $order->subtotal);
    }

    public function test_public_buy_endpoint_refuses_the_free_cloud_sale(): void
    {
        $this->plan();

        $r = $this->postJson('/buy/order', [
            'company_name' => 'Cloud Co', 'contact_name' => 'A B', 'email' => 'buy600@test.in',
            'password' => 'secret12345', 'state_code' => '36', 'kind' => 'cloud',
            'users' => 600, 'billing' => 'annual', 'terms_accepted' => 1,
        ]);

        $r->assertStatus(422);
        $this->assertStringContainsString('custom Cloud quotation', $r->json('error'));
    }
}
