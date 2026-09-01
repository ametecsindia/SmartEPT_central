<?php

namespace Tests\Feature;

use App\Http\Controllers\Client\PortalController;
use App\Models\DownloadArtifact;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A client's Install & Downloads page resolves each slot by WHAT THE ROW IS
 * (category + platform), not by its slug (1-Sep-2026).
 *
 * A slug is assigned once by uniqueSlug() at creation and never realigned, so:
 *  - "+ Add download" produced server-windows-2 / -3, invisible to every client;
 *  - a row created as a macOS agent and later switched to the Admin Server kept
 *    the slug `agent-mac` and offered the server zip in the macOS agent slot.
 */
class DownloadSlotResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function file(string $name): void
    {
        $dir = storage_path('app/downloads');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $name, 'installer');
    }

    /** The four canonical rows are seeded, so set up by slug rather than inserting duplicates. */
    private function artifact(array $attrs): DownloadArtifact
    {
        $slug = $attrs['slug'];
        unset($attrs['slug']);

        return DownloadArtifact::updateOrCreate(['slug' => $slug], array_merge([
            'title' => 'X', 'is_published' => true, 'sort' => 0, 'filename' => null,
        ], $attrs));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Start from an empty catalogue so each case states its own world.
        DownloadArtifact::query()->delete();
    }

    /** The reported bug: the Admin Server was uploaded onto a NON-canonical slug. */
    public function test_admin_server_added_as_a_new_row_still_reaches_the_client(): void
    {
        $this->file('SmartEPT-Admin-Server.zip');

        // Canonical row exists but is empty — exactly the live state.
        $this->artifact(['slug' => 'server-windows', 'category' => 'server',
            'platform' => 'windows', 'filename' => null, 'is_published' => false]);
        // Operator used "+ Add download", so this got server-windows-2.
        $this->artifact(['slug' => 'server-windows-2', 'category' => 'server',
            'platform' => 'windows', 'filename' => 'SmartEPT-Admin-Server.zip']);

        $this->assertNotNull(PortalController::artifactPath('server-windows'),
            'the Admin Server never reached the client');
    }

    /** A row wearing another slot's slug must not pollute that slot. */
    public function test_a_server_row_holding_the_mac_agent_slug_does_not_fill_the_mac_slot(): void
    {
        $this->file('SmartEPT-Admin-Server.zip');
        $this->artifact(['slug' => 'agent-mac', 'category' => 'server',
            'platform' => 'windows', 'filename' => 'SmartEPT-Admin-Server.zip']);

        $this->assertNull(PortalController::artifactPath('agent-mac'),
            'the Admin Server zip was offered as the macOS agent');
        $this->assertNotNull(PortalController::artifactPath('server-windows'),
            'it should resolve as the Windows server instead');
    }

    public function test_an_unpublished_slot_stays_unavailable(): void
    {
        $this->file('SmartEPT-Agent-Setup-1.0.exe');
        $this->artifact(['slug' => 'agent-windows', 'category' => 'agent', 'platform' => 'windows',
            'filename' => 'SmartEPT-Agent-Setup-1.0.exe', 'is_published' => false]);

        $this->assertNull(PortalController::artifactPath('agent-windows'));
    }

    public function test_portal_api_reports_the_admin_server_as_ready(): void
    {
        $this->file('SmartEPT-Admin-Server.zip');
        $this->artifact(['slug' => 'server-windows-3', 'category' => 'server', 'platform' => 'windows',
            'filename' => 'SmartEPT-Admin-Server.zip', 'version' => '2.1.0']);

        $tenant = Tenant::create(['company_name' => 'On Prem Co', 'email' => 'op@test.in',
            'deployment' => 'client_hosted', 'status' => 'active', 'state_code' => '36']);
        $user = TenantUser::create(['tenant_id' => $tenant->id, 'name' => 'O P', 'email' => 'op@test.in',
            'password' => 'secret12345', 'role' => 'owner', 'active' => 1]);

        $r = $this->actingAs($user, 'client')->getJson('/client/api/downloads')->assertOk();

        $this->assertTrue($r->json('admin_ready'), 'Admin Server still not offered to the client');
        $this->assertSame('2.1.0', $r->json('server.version'));
    }
}
