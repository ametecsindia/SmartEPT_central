<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            PricingV2Seeder::class,
            SettingSeeder::class,
            AdminUserSeeder::class,
            DemoSeeder::class,
            TenantUserSeeder::class,
        ]);
    }
}
