<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Database\Seeder;

/**
 * SAMPLE DATA — portal logins for the demo tenants so Ejaz can explore /client.
 * Password for all: `password` — CHANGE/DELETE before production.
 * Safe to run repeatedly (skips tenants that already have a user).
 */
class TenantUserSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::doesntHave('users')->get()->each(function (Tenant $t) {
            TenantUser::create([
                'tenant_id' => $t->id,
                'name' => $t->contact_name ?: $t->company_name,
                'email' => $t->email,
                'phone' => $t->phone,
                'password' => 'password',
                'role' => 'owner',
                'email_verified_at' => now(),
            ]);
        });
    }
}
