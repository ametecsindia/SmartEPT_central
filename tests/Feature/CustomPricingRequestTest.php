<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\BillingService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CUSTOM PRICING + REQUEST QUEUE (Ejaz, 13-Aug-2026).
 *
 * 1. An operator-entered custom price replaces the band calculation for ANY
 *    user count (incl. beyond the last priced milestone) — AMC/setup/coupon/
 *    GST behave as on a calculated order; ₹0 stays impossible.
 * 2. Public /buy captures a beyond-band visitor's full details (no price) as
 *    a 'request' row (source=client); staff edit, price and convert it IN
 *    PLACE into a numbered quotation on the existing golden path.
 */
class CustomPricingRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    /** Same milestone configuration as the progressive-pricing suite: 201+ = Custom. */
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

    private function tenant(array $extra = []): Tenant
    {
        return Tenant::create(array_merge([
            'company_name' => 'Custom Co', 'email' => 'custom@test.in',
            'deployment' => 'client_hosted', 'state_code' => '36',
            'setup_fee_paid' => true, 'status' => 'active', 'currency' => 'INR',
        ], $extra));
    }

    // ------------------------------------------------------------------
    //  Custom price on order creation
    // ------------------------------------------------------------------

    public function test_custom_price_allows_above_band_perpetual_order(): void
    {
        $plan = $this->plan();
        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 450, [
            'kind' => 'perpetual', 'as_quote' => true, 'custom_price' => 175000,
        ]);

        $this->assertSame('quote', $order->status);
        $this->assertNotNull($order->quote_number);
        $this->assertSame('admin', $order->source);
        $this->assertSame(175000, $order->meta['custom_price']);
        $this->assertSame(450, $order->meta['devices']);
        $this->assertEqualsWithDelta(175000.0, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(175000 * 1.18, (float) $order->total, 0.5); // +18% GST
        $this->assertStringContainsString('special price', $order->description);
    }

    public function test_above_band_without_custom_price_still_refused(): void
    {
        $plan = $this->plan();
        $this->expectException(\RuntimeException::class);
        app(BillingService::class)->createOrder($this->tenant(), $plan, 450, ['kind' => 'perpetual']);
    }

    public function test_custom_price_overrides_inside_band_price_too(): void
    {
        $plan = $this->plan();
        // 100 users would band-price at ₹60,000 — the operator's ₹52,000 wins.
        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 100, [
            'kind' => 'perpetual', 'custom_price' => 52000,
        ]);

        $this->assertEqualsWithDelta(52000.0, (float) $order->subtotal, 0.01);
    }

    public function test_custom_price_with_coupon_discounts_before_gst(): void
    {
        $plan = $this->plan();
        \App\Models\Coupon::create(['code' => 'SPECIAL10', 'type' => 'flat', 'value' => 10000, 'active' => true]);

        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 450, [
            'kind' => 'perpetual', 'custom_price' => 175000, 'coupon_code' => 'SPECIAL10',
        ]);

        $this->assertEqualsWithDelta(165000.0, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(165000 * 1.18, (float) $order->total, 0.5);
    }

    // ------------------------------------------------------------------
    //  Custom setup / AMC charges (13-Aug-2026, same session)
    // ------------------------------------------------------------------

    public function test_custom_setup_charge_forces_and_replaces_setup_fee(): void
    {
        $plan = $this->plan();
        // Tenant already PAID setup once — the filled box still charges it, at the operator's figure.
        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 100, [
            'kind' => 'perpetual', 'custom_setup' => 3000,
        ]);

        $this->assertEqualsWithDelta(63000.0, (float) $order->subtotal, 0.01); // 60,000 band + 3,000 custom setup
        $this->assertSame(3000, $order->meta['custom_setup']);
        $this->assertTrue(collect($order->line_items)->contains(fn ($l) => ($l['type'] ?? '') === 'setup_fee'));
    }

    public function test_custom_amc_adds_amc_line(): void
    {
        $plan = $this->plan();
        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 100, [
            'kind' => 'perpetual', 'custom_amc' => 9000,
        ]);

        $this->assertEqualsWithDelta(69000.0, (float) $order->subtotal, 0.01); // 60,000 band + 9,000 AMC
        $this->assertSame(9000, $order->meta['custom_amc']);
        $this->assertTrue(collect($order->line_items)->contains(fn ($l) => ($l['type'] ?? '') === 'amc'));
    }

    public function test_all_three_custom_boxes_together_cloud(): void
    {
        $plan = $this->plan();
        $t = $this->tenant(['email' => 'cloud@test.in', 'deployment' => 'cloud', 'setup_fee_paid' => false]);
        $order = app(BillingService::class)->createOrder($t, $plan, 300, [
            'kind' => 'subscription', 'billing' => 'annual',
            'custom_price' => 240000, 'custom_setup' => 12000, 'custom_amc' => 15000,
        ]);

        $this->assertEqualsWithDelta(267000.0, (float) $order->subtotal, 0.01); // 240k + 12k + 15k
        $this->assertEqualsWithDelta(267000 * 1.18, (float) $order->total, 0.5);
    }

    // ------------------------------------------------------------------
    //  Public /buy request queue
    // ------------------------------------------------------------------

    public function test_public_custom_quote_creates_request_row(): void
    {
        $this->plan();

        $res = $this->postJson('/buy/custom-quote', [
            'company_name' => 'BigCorp Ltd', 'contact_name' => 'Ravi Kumar',
            'billing_contact' => 'Accounts Head — Suresh', 'email' => 'ravi@bigcorp.in',
            'phone' => '9848012345', 'state_code' => '36', 'users' => 450,
            'notes' => '450 users across 3 branches.',
        ]);

        $res->assertStatus(201)->assertJson(['ok' => true]);

        $order = Order::latest('id')->first();
        $this->assertSame('request', $order->status);
        $this->assertSame('client', $order->source);
        $this->assertNull($order->quote_number);
        $this->assertEquals(0, (float) $order->total);
        $this->assertSame(450, $order->meta['devices']);
        $this->assertSame('Accounts Head — Suresh', $order->meta['billing_contact']);
        $this->assertSame('pending', $order->tenant->status);
        $this->assertSame('BigCorp Ltd', $order->tenant->company_name);
    }

    public function test_public_custom_quote_honeypot_creates_nothing(): void
    {
        $this->plan();
        $before = Order::count();

        $this->postJson('/buy/custom-quote', ['website_hp' => 'bot', 'company_name' => 'X'])
            ->assertStatus(201)->assertJson(['ok' => true]);

        $this->assertSame($before, Order::count());
    }

    // ------------------------------------------------------------------
    //  Convert request → numbered quotation, in place
    // ------------------------------------------------------------------

    public function test_convert_request_prices_in_place_and_keeps_identity(): void
    {
        $plan = $this->plan();

        $this->postJson('/buy/custom-quote', [
            'company_name' => 'BigCorp Ltd', 'contact_name' => 'Ravi Kumar',
            'email' => 'ravi@bigcorp.in', 'state_code' => '36', 'users' => 450,
            'billing_contact' => 'Suresh', 'notes' => 'multi-branch',
        ])->assertStatus(201);

        $order = Order::latest('id')->first();
        $number = $order->number;

        $converted = app(BillingService::class)->convertRequest($order, $plan, [
            'custom_price' => 160000, 'as_quote' => true, 'include_setup' => false,
        ]);

        $this->assertSame($number, $converted->number);           // same row, same order number
        $this->assertSame('quote', $converted->status);
        $this->assertNotNull($converted->quote_number);
        $this->assertSame('client', $converted->source);          // badge survives
        $this->assertSame(160000, $converted->meta['custom_price']);
        $this->assertSame('Suresh', $converted->meta['billing_contact']); // request details kept
        $this->assertSame('multi-branch', $converted->meta['notes']);
        $this->assertEqualsWithDelta(160000 * 1.18, (float) $converted->total, 0.5);
    }

    public function test_convert_refuses_non_request_rows(): void
    {
        $plan = $this->plan();
        $order = app(BillingService::class)->createOrder($this->tenant(), $plan, 100, [
            'kind' => 'perpetual', 'as_quote' => true,
        ]);

        $this->expectException(\RuntimeException::class);
        app(BillingService::class)->convertRequest($order, $plan, ['custom_price' => 50000]);
    }

    public function test_convert_without_custom_price_uses_band_price_when_available(): void
    {
        $plan = $this->plan();

        $this->postJson('/buy/custom-quote', [
            'company_name' => 'MidCo', 'contact_name' => 'Asha',
            'email' => 'asha@midco.in', 'state_code' => '36', 'users' => 100,
        ])->assertStatus(201);

        $order = Order::latest('id')->first();
        $converted = app(BillingService::class)->convertRequest($order, $plan, ['include_setup' => false]);

        $this->assertEqualsWithDelta(60000.0, (float) $converted->subtotal, 0.01); // 100-user milestone
    }

    public function test_convert_without_custom_price_above_bands_refused(): void
    {
        $plan = $this->plan();

        $this->postJson('/buy/custom-quote', [
            'company_name' => 'HugeCo', 'contact_name' => 'Dev',
            'email' => 'dev@hugeco.in', 'state_code' => '36', 'users' => 900,
        ])->assertStatus(201);

        $order = Order::latest('id')->first();
        $this->expectException(\RuntimeException::class);
        app(BillingService::class)->convertRequest($order, $plan, ['include_setup' => false]);
    }
}
