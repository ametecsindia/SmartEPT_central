<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BillingService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
        config(['app.debug' => true]); // expose demo_otp in responses, like Laragon test mode
    }

    private function makeClient(array $tenantAttrs = []): TenantUser
    {
        $tenant = Tenant::create(array_merge([
            'company_name' => 'Portal Co', 'email' => 'owner@portal.in', 'status' => 'active',
        ], $tenantAttrs));

        return TenantUser::create([
            'tenant_id' => $tenant->id, 'name' => 'Portal Owner',
            'email' => $tenant->email, 'password' => 'secret-123', 'role' => 'owner',
        ]);
    }

    public function test_client_auth_wall(): void
    {
        $this->get('/client')->assertRedirect('/client/login');
        $this->getJson('/client/api/overview')->assertStatus(401);
    }

    public function test_signup_with_otp_provisions_trial_tenant(): void
    {
        $payload = [
            'company_name' => 'Vizag Collections', 'contact_name' => 'Ravi Teja',
            'email' => 'ravi@vizagcollections.in', 'phone' => '9848011111',
            'password' => 'strongpass1',
            // Master-prompt pass (16-Jul): state is REQUIRED (drives the GST
            // split) and Terms+Refund consent is timestamped.
            'state_code' => '37', 'terms_accepted' => true,
        ];

        $res = $this->postJson('/client/signup/request-otp', $payload)->assertOk();
        $code = $res->json('demo_otp');
        $this->assertMatchesRegularExpression('/^\\d{6}$/', $code);

        // Wrong code is refused and does not create anything.
        $this->postJson('/client/signup/verify', $payload + ['otp' => $code === '000000' ? '000001' : '000000'])
            ->assertStatus(422);
        $this->assertSame(0, Tenant::count());

        // Correct code → tenant + owner login + auto 7-day Professional trial.
        $this->postJson('/client/signup/verify', $payload + ['otp' => $code])
            ->assertOk()->assertJsonPath('redirect', '/client');

        $tenant = Tenant::where('email', 'ravi@vizagcollections.in')->firstOrFail();
        $this->assertSame('trial', $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);

        $licence = $tenant->licences()->first();
        $this->assertSame('trial', $licence->kind);
        $this->assertSame(10, $licence->device_limit);
        $this->assertSame('professional', $licence->plan->code);

        // The session is already signed in.
        $this->getJson('/client/api/overview')->assertOk()
            ->assertJsonPath('tenant.company_name', 'Vizag Collections')
            ->assertJsonPath('licence.kind', 'trial');

        // The same email cannot sign up twice.
        $this->postJson('/client/signup/request-otp', $payload)->assertStatus(422);
    }

    public function test_login_logout_and_overview(): void
    {
        $user = $this->makeClient();

        $this->postJson('/client/login', ['email' => $user->email, 'password' => 'wrong-pass'])
            ->assertStatus(422);

        $this->postJson('/client/login', ['email' => $user->email, 'password' => 'secret-123'])
            ->assertOk()->assertJsonPath('redirect', '/client');

        $this->getJson('/client/api/overview')->assertOk()
            ->assertJsonPath('tenant.company_name', 'Portal Co');

        $this->post('/client/logout')->assertRedirect('/client/login');
        $this->getJson('/client/api/overview')->assertStatus(401);
    }

    public function test_self_service_purchase_creates_order_with_pay_link(): void
    {
        $user = $this->makeClient();
        $this->actingAs($user, 'client');

        $res = $this->postJson('/client/api/orders', [
            'plan_code' => 'professional', 'devices' => 50,
            'billing' => 'annual', 'deployment' => 'client_hosted',
        ])->assertStatus(201);

        // 50 × ₹49 × 12 = 29,400 + 7,500 setup + 18% GST = 43,542 (framework example)
        $this->assertEquals(43542.0, (float) $res->json('order.total'));
        $this->assertStringContainsString('/pay/', $res->json('pay_url'));
    }

    public function test_quotation_raised_from_portal_carries_requester(): void
    {
        $user = $this->makeClient();
        $this->actingAs($user, 'client');

        $res = $this->postJson('/client/api/orders', [
            'plan_code' => 'professional', 'devices' => 25,
            'billing' => 'annual', 'deployment' => 'client_hosted', 'as_quote' => true,
        ])->assertStatus(201);

        $this->assertSame('quote', $res->json('order.status'));
        $this->assertMatchesRegularExpression('/^EPT-Q-\\d{4}-\\d{2}-\\d{2}-\\d{4}$/', $res->json('order.quote_number'));

        $order = \App\Models\Order::findOrFail($res->json('order.id'));
        $this->assertSame('Portal Owner', $order->requested_by);
    }

    public function test_one_click_renewal_extends_same_licence(): void
    {
        $user = $this->makeClient();
        $billing = app(BillingService::class);
        $pro = Plan::where('code', 'professional')->first();

        // First purchase (pays setup fee, issues the licence).
        $first = $billing->createOrder($user->tenant, $pro, 50, ['kind' => 'subscription', 'billing' => 'annual']);
        $billing->markPaid($first, ['gateway' => 'manual', 'manual_method' => 'NEFT']);
        $licence = $user->tenant->fresh()->licences()->first();
        $originalExpiry = $licence->expires_at->copy();

        $this->actingAs($user, 'client');
        $res = $this->postJson('/client/api/licences/' . $licence->id . '/renew')->assertStatus(201);

        // Renewal = licence line only (setup fee already paid): 29,400 + GST = 34,692
        $this->assertEquals(34692.0, (float) $res->json('order.total'));

        $order = \App\Models\Order::findOrFail($res->json('order.id'));
        $this->assertSame($licence->id, $order->licence_id);

        $billing->markPaid($order, ['gateway' => 'manual', 'manual_method' => 'UPI']);

        // Same licence extended one year — no second licence issued.
        $this->assertSame(1, $user->tenant->licences()->count());
        $this->assertTrue($licence->fresh()->expires_at->eq($originalExpiry->addYear()));
    }

    public function test_client_cannot_see_another_tenants_documents(): void
    {
        $userA = $this->makeClient();
        $userB = $this->makeClient(['email' => 'other@other.in', 'company_name' => 'Other Co']);

        $billing = app(BillingService::class);
        $pro = Plan::where('code', 'professional')->first();
        $order = $billing->createOrder($userB->tenant, $pro, 10, ['kind' => 'subscription', 'billing' => 'annual']);
        $paid = $billing->markPaid($order, ['gateway' => 'manual', 'manual_method' => 'NEFT']);

        $this->actingAs($userA, 'client');
        $this->get('/client/invoices/' . $paid->invoice->id . '/print')->assertStatus(404);

        $this->actingAs($userB, 'client');
        $this->get('/client/invoices/' . $paid->invoice->id . '/print')->assertOk();
    }

    public function test_password_reset_via_otp(): void
    {
        $user = $this->makeClient();

        $res = $this->postJson('/client/forgot/request-otp', ['email' => $user->email])->assertOk();
        $code = $res->json('demo_otp');
        $this->assertMatchesRegularExpression('/^\\d{6}$/', $code);

        // Unknown email answers identically but sends nothing.
        $this->postJson('/client/forgot/request-otp', ['email' => 'nobody@nowhere.in'])
            ->assertOk()->assertJsonPath('demo_otp', null);

        $this->postJson('/client/forgot/reset', [
            'email' => $user->email, 'otp' => $code, 'password' => 'brand-new-pass',
        ])->assertOk();

        $this->postJson('/client/login', ['email' => $user->email, 'password' => 'brand-new-pass'])
            ->assertOk();
    }
}
