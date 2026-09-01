<?php

namespace Tests\Feature;

use App\Models\Licence;
use App\Models\Plan;
use App\Models\ProductUpdate;
use App\Models\Tenant;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The update feed on-prem servers hit when an admin presses "Check for Update"
 * (Ejaz, 1-Sep-2026). What is being pinned down here:
 *
 *  - only a NEWER, published build with a real file on disk is ever offered;
 *  - a licence that is not entitled (expired / suspended / AMC over / bound to
 *    another server) is refused with a reason the client can act on;
 *  - the check NEVER writes to the licence row — a version check must not bind
 *    a fingerprint or move last_validated_at;
 *  - the download token is what fetches the package, not the licence key.
 */
class ProductUpdateFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
        $dir = storage_path(ProductUpdate::DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path(ProductUpdate::DIR) . '/test-*.zip') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function licence(array $overrides = []): Licence
    {
        $tenant = Tenant::create([
            'company_name' => 'CGS Enterprises', 'email' => 'ops@cgs.test',
            'deployment' => 'client_hosted', 'state_code' => '36',
        ]);

        return Licence::create(array_merge([
            'key' => 'SEPT-TEST-0001', 'tenant_id' => $tenant->id,
            'plan_id' => Plan::where('code', 'smartept')->firstOrFail()->id,
            'kind' => 'subscription', 'billing' => 'annual', 'deployment' => 'client_hosted',
            'device_limit' => 10, 'status' => 'active',
            'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(), 'grace_days' => 7,
        ], $overrides));
    }

    private function package(string $version, array $overrides = []): ProductUpdate
    {
        $name = 'test-smartept-' . $version . '.zip';
        file_put_contents(storage_path(ProductUpdate::DIR . '/' . $name), 'PK-not-a-real-zip');

        return ProductUpdate::create(array_merge([
            'product' => 'smartept', 'version' => $version, 'channel' => 'stable',
            'filename' => $name, 'sha256' => hash('sha256', 'PK-not-a-real-zip'),
            'size_bytes' => 17, 'is_published' => true, 'released_at' => now(),
        ], $overrides));
    }

    public function test_a_newer_published_build_is_offered(): void
    {
        $licence = $this->licence();
        $this->package('1.6.0', ['notes' => 'Biometric fixes']);

        $res = $this->postJson('/api/v1/updates/check', [
            'key' => $licence->key, 'current_version' => '1.5.0',
        ]);

        $res->assertOk()
            ->assertJson(['ok' => true, 'update_available' => true, 'version' => '1.6.0'])
            ->assertJsonPath('notes', 'Biometric fixes');

        $this->assertStringContainsString('/api/v1/updates/download/', $res->json('download_url'));
    }

    public function test_a_server_already_on_the_latest_build_is_told_so(): void
    {
        $licence = $this->licence();
        $this->package('1.6.0');

        $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.6.0'])
            ->assertOk()
            ->assertJson(['ok' => true, 'update_available' => false]);
    }

    public function test_draft_builds_and_rows_without_a_file_are_never_offered(): void
    {
        $licence = $this->licence();
        $this->package('1.7.0', ['is_published' => false]);           // draft
        ProductUpdate::create(['product' => 'smartept', 'version' => '1.8.0', 'channel' => 'stable',
            'is_published' => true, 'filename' => 'gone.zip']);        // published, file missing

        $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.5.0'])
            ->assertOk()
            ->assertJson(['update_available' => false]);
    }

    public function test_a_build_is_withheld_from_an_installation_below_its_minimum_version(): void
    {
        $licence = $this->licence();
        $this->package('2.0.0', ['min_version' => '1.9.0']);

        $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.5.0'])
            ->assertOk()
            ->assertJson(['update_available' => false]);

        $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.9.0'])
            ->assertOk()
            ->assertJson(['update_available' => true, 'version' => '2.0.0']);
    }

    public function test_an_expired_licence_is_refused_with_a_reason(): void
    {
        $licence = $this->licence(['expires_at' => now()->subMonths(2), 'grace_days' => 7]);
        $this->package('1.6.0');

        $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.5.0'])
            ->assertStatus(403)
            ->assertJson(['ok' => false, 'reason' => 'licence_expired']);
    }

    public function test_a_perpetual_licence_out_of_amc_is_refused(): void
    {
        $licence = $this->licence(['kind' => 'perpetual', 'expires_at' => null,
            'amc_expires_at' => now()->subDay()]);
        $this->package('1.6.0');

        $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.5.0'])
            ->assertStatus(403)
            ->assertJson(['reason' => 'amc_expired']);
    }

    /**
     * A different fingerprint must NOT block an update (1-Sep-2026, found live).
     *
     * server_fingerprint holds the 40-char MACHINE id when a .lic was issued
     * (licenseFile() writes it), but the phone-home sends the 64-char
     * sha256(app.key|hostname). They are different identifiers, so comparing
     * them refused every offline-licensed client with "bound to a different
     * server". Cloning is caught by the daily validate(), which logs it.
     */
    public function test_a_different_fingerprint_does_not_block_an_update(): void
    {
        $licence = $this->licence(['server_fingerprint' => str_repeat('f', 40)]);   // machine id
        $this->package('1.6.0');

        $this->postJson('/api/v1/updates/check', [
            'key' => $licence->key, 'current_version' => '1.5.0',
            'fingerprint' => hash('sha256', 'app-key|host'),                        // phone-home id
        ])->assertOk()->assertJson(['update_available' => true, 'version' => '1.6.0']);
    }

    /** A version check is read-only: it must not bind a machine or move the licence clock. */
    public function test_the_check_never_writes_to_the_licence(): void
    {
        $licence = $this->licence();
        $this->package('1.6.0');
        $before = $licence->fresh()->only(['server_fingerprint', 'last_validated_at', 'activated_at']);

        $this->postJson('/api/v1/updates/check', [
            'key' => $licence->key, 'current_version' => '1.5.0', 'fingerprint' => 'a-brand-new-pc',
        ])->assertOk();

        $this->assertSame($before, $licence->fresh()->only(['server_fingerprint', 'last_validated_at', 'activated_at']));
    }

    public function test_the_package_downloads_with_the_token_and_not_with_the_key(): void
    {
        $licence = $this->licence();
        $this->package('1.6.0');

        $url = $this->postJson('/api/v1/updates/check', ['key' => $licence->key, 'current_version' => '1.5.0'])
            ->json('download_url');

        $this->get($url)->assertOk()->assertHeader('X-SmartEPT-Version', '1.6.0');
        $this->get('/api/v1/updates/download/not-a-real-token')->assertStatus(410);
    }

    public function test_an_unknown_key_gets_no_update_and_no_hint(): void
    {
        $this->package('1.6.0');

        $this->postJson('/api/v1/updates/check', ['key' => 'SEPT-NOPE', 'current_version' => '1.5.0'])
            ->assertStatus(403)
            ->assertJson(['reason' => 'unknown_key', 'update_available' => false]);
    }
}
