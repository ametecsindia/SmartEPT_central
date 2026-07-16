<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\BillingService;
use App\Support\AmountInWords;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Release-1 GST compliance: the CGST/SGST/IGST split is a BREAKDOWN of the
 * same 18% gst_amount — subtotal/total stay exactly as before (the smoke
 * tests keep guarding those).
 */
class GstInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function paidInvoiceFor(array $tenantAttrs)
    {
        $billing = app(BillingService::class);
        $tenant = Tenant::create(array_merge([
            'company_name' => 'GST Co', 'email' => 'gst@test.in', 'deployment' => 'client_hosted',
        ], $tenantAttrs));
        $pro = Plan::where('code', 'professional')->first();

        // 50 × ₹49 × 12 = 29,400 + setup 7,500 = 36,900 + 18% GST (6,642) = 43,542
        $order = $billing->createOrder($tenant, $pro, 50, ['kind' => 'subscription', 'billing' => 'annual']);
        $paid = $billing->markPaid($order, ['gateway' => 'manual', 'manual_method' => 'NEFT']);

        return $paid->invoice;
    }

    public function test_intra_state_invoice_splits_tax_into_cgst_and_sgst(): void
    {
        $invoice = $this->paidInvoiceFor([
            'gstin' => '36AAAAA0000A1Z5', 'state_code' => '36',
            'billing_address' => 'Hitech City, Hyderabad, Telangana 500081',
        ]);

        // Same 18% tax as always — split half and half, IGST untouched.
        $this->assertEquals(6642.0, (float) $invoice->gst_amount);
        $this->assertEquals(3321.0, (float) $invoice->cgst);
        $this->assertEquals(3321.0, (float) $invoice->sgst);
        $this->assertEquals(0.0, (float) $invoice->igst);
        $this->assertEquals((float) $invoice->gst_amount, (float) $invoice->cgst + (float) $invoice->sgst);

        // Snapshots for the tax document.
        $this->assertSame('36-Telangana', $invoice->place_of_supply);
        $this->assertSame('36AAAAA0000A1Z5', $invoice->buyer_gstin);
        $this->assertSame('997331', $invoice->sac_code);

        // The invoice grand total is untouched by the split.
        $this->assertEquals(43542.0, (float) $invoice->total);
    }

    public function test_inter_state_invoice_charges_igst(): void
    {
        $invoice = $this->paidInvoiceFor([
            'gstin' => '29AAAAA0000A1Z5', 'state_code' => '29',
        ]);

        $this->assertEquals(6642.0, (float) $invoice->igst);
        $this->assertEquals(0.0, (float) $invoice->cgst);
        $this->assertEquals(0.0, (float) $invoice->sgst);
        $this->assertEquals(6642.0, (float) $invoice->gst_amount);
        $this->assertSame('29-Karnataka', $invoice->place_of_supply);
        $this->assertEquals(43542.0, (float) $invoice->total);
    }

    public function test_tenant_without_state_defaults_to_local_supply(): void
    {
        // Unregistered buyer with no declared state = supply at the seller's
        // place of business (Telangana) → intra-state split, never IGST.
        $invoice = $this->paidInvoiceFor([]);

        $this->assertEquals(3321.0, (float) $invoice->cgst);
        $this->assertEquals(3321.0, (float) $invoice->sgst);
        $this->assertEquals(0.0, (float) $invoice->igst);
        $this->assertSame('36-Telangana', $invoice->place_of_supply);
        $this->assertNull($invoice->buyer_gstin);
    }

    public function test_amount_in_words_indian_numbering(): void
    {
        $this->assertSame('Rupees Zero Only', AmountInWords::convert(0));
        $this->assertSame(
            'Rupees Forty Three Thousand Five Hundred Forty Two Only',
            AmountInWords::convert(43542.00)
        );
        // 1,23,45,678.50 — crore/lakh grouping + paise.
        $this->assertSame(
            'Rupees One Crore Twenty Three Lakh Forty Five Thousand Six Hundred Seventy Eight and Fifty Paise Only',
            AmountInWords::convert(12345678.50)
        );
    }

    public function test_legal_pages_are_public(): void
    {
        foreach (['/privacy', '/terms', '/refunds', '/contact'] as $page) {
            $this->get($page)->assertOk();
        }
    }

    public function test_client_can_read_and_update_billing_profile(): void
    {
        $tenant = Tenant::create(['company_name' => 'Profile Co', 'email' => 'p@profile.in', 'status' => 'active']);
        $user = TenantUser::create([
            'tenant_id' => $tenant->id, 'name' => 'Profile Owner',
            'email' => $tenant->email, 'password' => 'secret-123', 'role' => 'owner',
        ]);
        $this->actingAs($user, 'client');

        // Fresh account: everything empty but the state list is offered.
        $this->getJson('/client/api/account/billing')->assertOk()
            ->assertJsonPath('gstin', null)
            ->assertJsonPath('states.36', 'Telangana');

        $this->putJson('/client/api/account/billing', [
            'gstin' => '36AAHCT0971F1ZB',
            'state_code' => '36',
            'billing_address' => 'Kondapur, Hyderabad, Telangana 500084',
        ])->assertOk()->assertJsonPath('ok', true);

        $tenant->refresh();
        $this->assertSame('36AAHCT0971F1ZB', $tenant->gstin);
        $this->assertSame('36', $tenant->state_code);
        $this->assertSame('Kondapur, Hyderabad, Telangana 500084', $tenant->billing_address);

        // State omitted → derived from the first two GSTIN digits.
        $this->putJson('/client/api/account/billing', ['gstin' => '29AAHCT0971F1ZB'])->assertOk();
        $this->assertSame('29', $tenant->fresh()->state_code);

        // Malformed GSTIN is refused.
        $this->putJson('/client/api/account/billing', ['gstin' => 'NOT-A-GSTIN'])->assertStatus(422);
    }
}
