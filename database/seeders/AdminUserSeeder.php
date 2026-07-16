<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::firstOrCreate(
            ['email' => 'ejaz@ametecsindia.com'],
            ['name' => 'Ejaz Hussain', 'password' => 'password', 'role' => 'super']
        );
        AdminUser::firstOrCreate(
            ['email' => 'sales@ametecsindia.com'],
            ['name' => 'Sales Desk', 'password' => 'password', 'role' => 'sales']
        );
        AdminUser::firstOrCreate(
            ['email' => 'support@ametecsindia.com'],
            ['name' => 'Support Desk', 'password' => 'password', 'role' => 'support']
        );
        // ⚠ All seeded passwords are 'password' — change after first login.
    }
}
