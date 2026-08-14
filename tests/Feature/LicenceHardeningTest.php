<?php

namespace Tests\Feature;

use App\Models\Licence;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\LicenceService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licence hardening (Ejaz's findings, 14-Aug-2026).
 *
 *  1.1  A paid licence closes the client's still-active trial.
 *  1.3  The client console's reported user/employee/device counts are stored,
 *       so Central stops showing "0/25" for a client running 14 people.
 */
class LicenceHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'company_name' => 'Skill Dunya', 'email' => 'trial@test.in',
            'deployment' => 'cloud', 'state_code' => '36',
        ]);
    }

    private function plan(): Plan
    {
        return Plan::where('code', 'smartept')->firstOrFail();
    }

    public function test_a_paid_licence_closes_the_clients_active_trial(): void
    {
        $tenant = $this->tenant();
        $licences = app(LicenceService::class);

        $trial = $licences->issue($tenant, $this->plan(), ['kind' => 'trial', 'device_limit' => 10]);
        $this->assertSame('active', $trial->status);

        $paid = $licences->issue($tenant, $this->plan(), ['kind' => 'subscription', 'device_limit' => 25]);

        $this->assertSame('superseded', $trial->fresh()->status, 'the trial must not stay active');
        $this->assertSame('active', $paid->fresh()->status);
        $this->assertSame(1, Licence::where('tenant_id', $tenant->id)->where('status', 'active')->count());
    }

    public function test_another_clients_trial_is_left_alone(): void
    {
        $licences = app(LicenceService::class);

        $other = Tenant::create([
            'company_name' => 'Adaa Enterprises', 'email' => 'adaa@test.in',
            'deployment' => 'cloud', 'state_code' => '36',
        ]);
        $otherTrial = $licences->issue($other, $this->plan(), ['kind' => 'trial']);

        $licences->issue($this->tenant(), $this->plan(), ['kind' => 'subscription', 'device_limit' => 25]);

        $this->assertSame('active', $otherTrial->fresh()->status);
    }

    public function test_a_superseded_key_stops_validating(): void
    {
        $tenant = $this->tenant();
        $licences = app(LicenceService::class);

        $trial = $licences->issue($tenant, $this->plan(), ['kind' => 'trial']);
        $licences->issue($tenant, $this->plan(), ['kind' => 'subscription', 'device_limit' => 25]);

        $this->postJson('/api/v1/license/validate', ['key' => $trial->key])
            ->assertStatus(403)
            ->assertJsonPath('reason', 'licence_superseded');
    }

    public function test_client_reported_seat_counts_are_stored_on_the_licence(): void
    {
        $licence = app(LicenceService::class)
            ->issue($this->tenant(), $this->plan(), ['kind' => 'subscription', 'device_limit' => 25]);

        $this->postJson('/api/v1/license/validate', [
            'key' => $licence->key,
            'fingerprint' => 'test-machine',
            'users' => 14,
            'employees' => 13,
            'devices' => 2,
        ])->assertOk();

        $fresh = $licence->fresh();
        $this->assertSame(14, (int) $fresh->reported_users);
        $this->assertSame(13, (int) $fresh->reported_employees);
        $this->assertSame(2, (int) $fresh->reported_devices);
        $this->assertNotNull($fresh->reported_at);
    }

    public function test_counts_are_optional_so_older_consoles_still_validate(): void
    {
        $licence = app(LicenceService::class)
            ->issue($this->tenant(), $this->plan(), ['kind' => 'subscription', 'device_limit' => 25]);

        $this->postJson('/api/v1/license/validate', ['key' => $licence->key])->assertOk();

        $this->assertNull($licence->fresh()->reported_users);
    }
}
