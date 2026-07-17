<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Ensures EVERY client (tenant) has a /client portal owner login.
 * Requirement: every client can self-serve renewals, invoices, licences and
 * downloads. Tenants created before this rule (demo data, early admin-created
 * tenants) had no login; this backfills one and prints the credentials.
 */
class BackfillPortalLogins extends Command
{
    protected $signature = 'smartept:backfill-portal-logins {--password= : Use a fixed password for all (e.g. password) instead of random}';
    protected $description = 'Create a /client portal owner login for any client that lacks one';

    public function handle(): int
    {
        $fixed = $this->option('password');
        $made = 0;

        $this->info('Checking clients for portal logins...');
        $this->line(str_repeat('-', 60));

        Tenant::orderBy('id')->each(function (Tenant $t) use (&$made, $fixed) {
            if (TenantUser::where('email', $t->email)->exists()) {
                return; // already has a login
            }
            $pw = $fixed ?: Str::password(10);
            TenantUser::create([
                'tenant_id' => $t->id,
                'name' => $t->contact_name ?: $t->company_name,
                'email' => $t->email,
                'phone' => $t->phone,
                'password' => $pw,
                'role' => 'owner',
                'active' => 1,
                // Backfilled credentials are temporary by definition — force
                // create-your-own-password on first login (master prompt §11).
                'must_set_password' => true,
                'email_verified_at' => now(),
            ]);
            $made++;
            $this->line(sprintf('  %-32s  %s', $t->email, $pw));
        });

        $this->line(str_repeat('-', 60));
        $this->info($made ? "$made portal login(s) created. Share each with the client." : 'All clients already have a portal login. Nothing to do.');

        return self::SUCCESS;
    }
}
