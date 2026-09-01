<?php

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\ProductProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The client must be TOLD when their hosted console comes into existence
 * (1-Sep-2026).
 *
 * The mail used to be gated on temp_password alone, which the product returns
 * only on the FIRST provision. When a first provision created the console but
 * the response failed on the way out (SmartEPT_Admin 500'd while logging its own
 * success — see the storage-permission incident), every later retry came back
 * WITHOUT a temp password: Central saved the console URL and the client was
 * never told their console existed.
 */
class ConsoleProvisionNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::set('product_base_url', 'https://admin.example.test');
        Setting::set('product_provision_secret', 'test-secret');
    }

    private function tenant(array $extra = []): Tenant
    {
        return Tenant::create(array_merge([
            'company_name' => 'Aavaas Ltd', 'contact_name' => 'Ravi K',
            'email' => 'ravi@aavaas.test', 'deployment' => 'cloud',
            'status' => 'trial', 'state_code' => '36',
        ], $extra));
    }

    private function fakeProvision(array $body): void
    {
        Http::fake(['*/api/provision' => Http::response($body, 200)]);
    }

    public function test_first_provision_with_a_temp_password_emails_the_credentials(): void
    {
        $t = $this->tenant();
        $this->fakeProvision(['console_url' => 'https://admin.example.test/aavaas', 'temp_password' => 'Temp12345']);

        app(ProductProvisioner::class)->ensureFor($t);

        $this->assertSame('https://admin.example.test/aavaas', $t->fresh()->console_url);
        $mail = MailLog::latest('id')->first();
        $this->assertNotNull($mail, 'no email was sent at all');
        $this->assertStringContainsString('Temp12345', $mail->body);
        $this->assertStringContainsString('https://admin.example.test/aavaas', $mail->body);
    }

    /** The regression: console created, retry returns no temp password. */
    public function test_console_created_without_a_temp_password_still_notifies_the_client(): void
    {
        $t = $this->tenant();
        $this->fakeProvision(['console_url' => 'https://admin.example.test/aavaas']); // no temp_password

        app(ProductProvisioner::class)->ensureFor($t);

        $this->assertSame('https://admin.example.test/aavaas', $t->fresh()->console_url);
        $mail = MailLog::latest('id')->first();
        $this->assertNotNull($mail, 'client was never told their console exists');
        $this->assertStringContainsString('https://admin.example.test/aavaas', $mail->body);
        $this->assertStringContainsString('Open my SmartEPT Console', $mail->body);
        $this->assertStringNotContainsString('Temporary password', $mail->body);
    }

    /** A routine re-provision of an already-known console must stay quiet. */
    public function test_reprovisioning_a_known_console_does_not_email_again(): void
    {
        $t = $this->tenant(['console_url' => 'https://admin.example.test/aavaas']);
        $this->fakeProvision(['console_url' => 'https://admin.example.test/aavaas']);

        app(ProductProvisioner::class)->ensureFor($t);

        $this->assertNull(MailLog::latest('id')->first(), 'a silent re-provision spammed the client');
    }
}
