<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\ProductUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Upload Update refuses the INSTALLER zip (Ejaz, 1-Sep-2026).
 *
 * The installer wraps everything in SmartEPT-Admin-Server/. Published as an
 * update, an on-prem updater copies that folder INTO the client's app folder,
 * replaces nothing, and still reports success — three attempts at a client site
 * before the cause was found. The shape is checked here, where it costs nothing.
 */
class UpdatePackageShapeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Ejaz', 'email' => 'ejaz@ametecsindia.com',
            'password' => 'secret12345', 'role' => 'super', 'active' => 1,
        ]);
    }

    /** Build a zip in storage/app/updates; $prefix '' = flat, otherwise wrapped. */
    private function zip(string $name, string $prefix = ''): string
    {
        $dir = storage_path(ProductUpdate::DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . $name;
        @unlink($path);

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach (['artisan', 'config/app.php', 'app/Providers/AppServiceProvider.php', 'public/index.php'] as $f) {
            $zip->addFromString($prefix . $f, '<?php // ' . $f);
        }
        $zip->close();

        return $path;
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path(ProductUpdate::DIR) . '/shapetest-*.zip') ?: [] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    public function test_a_wrapped_installer_zip_is_refused_with_the_reason(): void
    {
        $this->zip('shapetest-setup.zip', 'SmartEPT-Admin-Server/');

        $res = $this->actingAs($this->admin(), 'admin')->post('/admin/api/product-updates', [
            'version' => '9.1', 'existing_file' => 'shapetest-setup.zip',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('SmartEPT-Admin-Server/', $res->json('message'));
        $this->assertStringContainsString('SmartEPT-Update-', $res->json('message'));
        $this->assertNull(ProductUpdate::where('version', '9.1')->first());
    }

    public function test_a_flat_update_package_is_accepted(): void
    {
        $this->zip('shapetest-update.zip');

        $this->actingAs($this->admin(), 'admin')->post('/admin/api/product-updates', [
            'version' => '9.2', 'existing_file' => 'shapetest-update.zip',
        ])->assertOk();

        $row = ProductUpdate::where('version', '9.2')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->sha256);
    }
}
