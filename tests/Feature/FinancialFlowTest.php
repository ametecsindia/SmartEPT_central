<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\MailLog;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BillingService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B2B Financial Flow master-prompt pass (16-Jul-2026):
 * credit provisioning (Paid/Partial/Due), the payments ledger, FY-consecutive
 * invoice numbering, quote-locked coupons, exclusive coupons, and the forced
 * create-your-own-password gate.
 */
class FinancialFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function admin(string $role = 'super'): AdminUser
    {
        return AdminUser::create([
            'name' => 'Test Admin', 'email' => $role . '@test.in',
            'password' => 'secret-123', 'role' => $role, 'active' => 1,
        ]);
    }

    private function tenant(array $attrs = []): Tenant
    {
        return Tenant::create(array_merge([
            'company_name' => 'Flow Co', 'email' => 'flow@test.in',
            'deployment' => 'client_hosted', 'state_code' => '36',
        ], $attrs));
    }

    /** 50 devices annual = ₹49 tier → 29,400 + setup 7,500 = 36,900 + GST 6,642 = 43,542 */
    private function order(Tenant $tenant, array $opts = []): Order
    {
        return app(BillingService::class)->createOrder(
            $tenant, Plan::where('code', 'professional')->first(), 50,
            $opts + ['kind' => 'subscription', 'billing' => 'annual']
        );
    }

    // ---------- FY-consecutive numbering (§12) ----------

    public function test_invoice_numbers_run_consecutively_through_the_fy(): void
    {
        $billing = app(BillingService::class);
        $t = $this->tenant();

        $i1 = $billing->markPaid($this->order($t))->invoice;
        $i2 = $billing->markPaid($this->order($t))->invoice;

        $fy = BillingService::fyLabel();
        $mm = now()->format('m');
        $this->assertSame("EPT-$fy-$mm-0001", $i1->number);
        $this->assertSame("EPT-$fy-$mm-0002", $i2->number);

        // Deleting a non-latest invoice never causes a duplicate — MAX+1, not COUNT+1.
        $i1->delete();
        $i3 = $billing->markPaid($this->order($t))->invoice;
        $this->assertSame("EPT-$fy-$mm-0003", $i3->number);
    }

    public function test_quotes_have_their_own_series(): void
    {
        $billing = app(BillingService::class);
        $t = $this->tenant();

        $q1 = $this->order($t, ['as_quote' => true]);
        $q2 = $this->order($t, ['as_quote' => true]);

        $fy = BillingService::fyLabel();
        $mm = now()->format('m');
        $this->assertSame("EPT-Q-$fy-$mm-0001", $q1->quote_number);
        $this->assertSame("EPT-Q-$fy-$mm-0002", $q2->quote_number);
    }

    // ---------- Credit provisioning (§10) ----------

    public function test_partial_payment_provisions_immediately_with_due_invoice(): void
    {
        $this->actingAs($this->admin('sales'), 'admin');
        $t = $this->tenant();
        $order = $this->order($t, ['as_quote' => true]);
        $due = now()->addDays(30)->toDateString();

        $this->postJson("/admin/api/orders/{$order->id}/mark-paid", [
            'payment_status' => 'partial', 'amount' => 20000,
            'manual_method' => 'NEFT', 'manual_reference' => 'UTR-111',
            'credit_due_date' => $due,
        ])->assertOk();

        $order->refresh();
        $this->assertNotNull($order->provisioned_at);          // workspace live NOW
        $this->assertNotNull($order->licence_id);              // licence issued
        $this->assertSame('created', $order->status);          // not yet settled
        $this->assertSame('active', $t->fresh()->status);
        $this->assertEquals(20000.0, $order->received());
        $this->assertEquals(23542.0, $order->balance());       // 43,542 − 20,000

        $invoice = $order->invoice;
        $this->assertSame('issued', $invoice->status);         // displays as DUE
        $this->assertSame($due, $invoice->due_date->toDateString());

        // Shows up in the Credit clients table, not overdue yet.
        $credit = $this->getJson('/admin/api/credit-clients')->assertOk()->json('data');
        $this->assertCount(1, $credit);
        $this->assertEquals(23542.0, (float) $credit[0]['balance']);
        $this->assertFalse($credit[0]['overdue']);
    }

    public function test_recording_the_balance_settles_and_emails_receipt_once(): void
    {
        $this->actingAs($this->admin('sales'), 'admin');
        $t = $this->tenant();
        $order = $this->order($t);

        $this->postJson("/admin/api/orders/{$order->id}/mark-paid", [
            'payment_status' => 'partial', 'amount' => 20000,
            'manual_method' => 'NEFT', 'credit_due_date' => now()->addDays(15)->toDateString(),
        ])->assertOk();

        $this->postJson("/admin/api/orders/{$order->id}/record-balance", [
            'amount' => 23542, 'manual_method' => 'UPI', 'manual_reference' => 'UTR-222',
        ])->assertOk()->assertJsonPath('settled', true);

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('paid', $order->invoice->status);    // DUE → PAID exactly at zero
        $this->assertEquals(0.0, $order->balance());
        $this->assertSame(2, $order->payments()->count());

        // Exactly one FULL receipt (part-payment ack is a different subject).
        $this->assertSame(1, MailLog::where('to_email', $t->email)
            ->where('subject', 'like', 'SmartEPT — payment received · Invoice%')->count());

        // Over-collecting is refused.
        $this->postJson("/admin/api/orders/{$order->id}/record-balance", [
            'amount' => 5, 'manual_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_due_credit_provisions_with_zero_received(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $t = $this->tenant();
        $order = $this->order($t, ['as_quote' => true]);

        $this->postJson("/admin/api/orders/{$order->id}/mark-paid", [
            'payment_status' => 'due', 'credit_due_date' => now()->addDays(45)->toDateString(),
        ])->assertOk();

        $order->refresh();
        $this->assertNotNull($order->provisioned_at);
        $this->assertNotNull($order->licence_id);
        $this->assertEquals(0.0, $order->received());
        $this->assertSame(0, $order->payments()->count());     // ₹0 → no ledger row
        $this->assertSame('issued', $order->invoice->status);
        $this->assertSame('created', $order->status);          // quote implicitly approved
    }

    public function test_missing_credit_due_date_is_rejected_for_partial_and_due(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $order = $this->order($this->tenant());

        $this->postJson("/admin/api/orders/{$order->id}/mark-paid", [
            'payment_status' => 'due',
        ])->assertStatus(422);

        $this->postJson("/admin/api/orders/{$order->id}/mark-paid", [
            'payment_status' => 'partial', 'amount' => 100, 'manual_method' => 'NEFT',
        ])->assertStatus(422);
    }

    public function test_plain_mark_paid_still_works_as_before(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $order = $this->order($this->tenant());

        $this->postJson("/admin/api/orders/{$order->id}/mark-paid", [
            'manual_method' => 'NEFT', 'manual_reference' => 'UTR-333',
        ])->assertOk();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertSame('paid', $order->invoice->status);
        $this->assertNull($order->invoice->due_date);
    }

    public function test_ledger_is_idempotent_per_gateway_payment_id(): void
    {
        $billing = app(BillingService::class);
        $order = $this->order($this->tenant());

        $billing->recordPayment($order, 43542, ['gateway' => 'razorpay', 'payment_id' => 'pay_ABC']);
        $billing->recordPayment($order->fresh(), 43542, ['gateway' => 'razorpay', 'payment_id' => 'pay_ABC']);

        $this->assertSame(1, OrderPayment::where('gateway_payment_id', 'pay_ABC')->count());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_overpayment_is_clamped_to_the_balance(): void
    {
        $billing = app(BillingService::class);
        $order = $this->order($this->tenant());

        $billing->recordPayment($order, 99999999, ['gateway' => 'manual', 'manual_method' => 'NEFT']);

        $this->assertEquals(43542.0, $order->fresh()->received());
    }

    // ---------- Coupons (§7) ----------

    public function test_coupon_is_locked_into_a_quote_and_redeemed_once_on_provisioning(): void
    {
        Coupon::create(['code' => 'DIWALI25', 'type' => 'percent', 'value' => 25, 'active' => true]);
        $billing = app(BillingService::class);
        $t = $this->tenant();

        $order = $this->order($t, ['as_quote' => true, 'coupon_code' => 'DIWALI25']);

        // 36,900 − 25% (9,225) = 27,675 + 18% GST (4,981.50) = 32,656.50
        $this->assertEquals(27675.0, (float) $order->subtotal);
        $this->assertEquals(32656.5, (float) $order->total);

        // Code expires meanwhile — the quote's pay link still honours the price
        // because the discount line is frozen in the order.
        Coupon::where('code', 'DIWALI25')->update(['active' => false]);
        $paid = $billing->markPaid($order, ['gateway' => 'manual', 'manual_method' => 'NEFT']);

        $this->assertEquals(32656.5, (float) $paid->total);
        $this->assertSame(1, (int) Coupon::where('code', 'DIWALI25')->value('used_count'));
    }

    public function test_exclusive_coupon_only_works_for_its_email(): void
    {
        Coupon::create(['code' => 'VIP50', 'type' => 'flat', 'value' => 5000,
            'exclusive_email' => 'cfo@bigclient.in', 'active' => true]);

        [$c1] = Coupon::check('VIP50', 10, 'someone@else.in');
        $this->assertNull($c1);                                 // hidden from strangers

        [$c2] = Coupon::check('VIP50', 10, 'CFO@BigClient.in'); // case-insensitive match
        $this->assertNotNull($c2);

        $this->assertNotNull(Coupon::exclusiveFor('cfo@bigclient.in'));
        $this->assertNull(Coupon::exclusiveFor('nobody@nowhere.in'));

        // Public exclusive-offer catch endpoint.
        $this->postJson('/api/v1/public/exclusive-offer', ['email' => 'cfo@bigclient.in'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('code', 'VIP50');
        $this->postJson('/api/v1/public/exclusive-offer', ['email' => 'someone@else.in'])
            ->assertOk()->assertJsonPath('ok', false);
    }

    // ---------- GSTIN ↔ state cross-check (§2/§6) ----------

    public function test_admin_tenant_create_rejects_gstin_state_mismatch(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->postJson('/admin/api/tenants', [
            'company_name' => 'Mismatch Co', 'email' => 'mm@test.in',
            'deployment' => 'client_hosted',
            'gstin' => '29AAHCT0971F1ZB', 'state_code' => '36',
        ])->assertStatus(422);
    }

    public function test_admin_tenant_create_makes_a_portal_login_with_forced_password(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $res = $this->postJson('/admin/api/tenants', [
            'company_name' => 'Login Co', 'email' => 'owner@loginco.in',
            'deployment' => 'client_hosted', 'state_code' => '36',
        ])->assertCreated();

        $this->assertNotEmpty($res->json('portal_temp_password'));

        $user = TenantUser::where('email', 'owner@loginco.in')->first();
        $this->assertNotNull($user);
        $this->assertTrue((bool) $user->must_set_password);

        // Setting an own password clears the gate.
        $this->actingAs($user, 'client');
        $this->postJson('/client/api/account/password', [
            'current_password' => $res->json('portal_temp_password'),
            'password' => 'my-own-password-9',
        ])->assertOk();
        $this->assertFalse((bool) $user->fresh()->must_set_password);
    }

    public function test_billing_profile_rejects_gstin_state_mismatch(): void
    {
        $t = $this->tenant(['email' => 'bp@test.in']);
        $user = TenantUser::create(['tenant_id' => $t->id, 'name' => 'BP',
            'email' => $t->email, 'password' => 'secret-123', 'role' => 'owner']);
        $this->actingAs($user, 'client');

        $this->putJson('/client/api/account/billing', [
            'gstin' => '29AAHCT0971F1ZB', 'state_code' => '36',
        ])->assertStatus(422);
    }

    // ---------- Checkout page: the credit link stays alive ----------

    public function test_pay_page_shows_received_and_balance_for_credit_orders(): void
    {
        $billing = app(BillingService::class);
        $order = $this->order($this->tenant());
        $billing->recordManualPayment($order, [
            'payment_status' => 'partial', 'amount' => 20000,
            'manual_method' => 'NEFT', 'credit_due_date' => now()->addDays(20)->toDateString(),
        ]);

        $token = \App\Http\Controllers\CheckoutController::token($order);
        $this->get("/pay/{$order->number}/{$token}")
            ->assertOk()
            ->assertSee('Received so far')
            ->assertSee('Balance payable');
    }
}
