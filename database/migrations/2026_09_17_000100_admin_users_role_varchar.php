<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENUM strike four (17-Sep-2026): admin_users.role is still the original
 * hard ENUM('super','sales','support') from 14-Jul, but PermissionService
 * (7-Aug) turned roles into a fully dynamic, matrix-driven set — any custom
 * role (e.g. "Accountant") created on the Users & Roles screen validates
 * fine against PermissionService::roleKeys(), but MySQL can't store a value
 * outside the original three and throws 1265 "Data truncated for column
 * 'role'" on insert. Same root cause as tenants.status (11-Aug) and
 * licences.status (14-Aug): the application layer owns the value set now,
 * the column must not restrict it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (tests) stores enums as TEXT already
        }

        DB::statement("ALTER TABLE `admin_users` MODIFY `role` VARCHAR(30) NOT NULL DEFAULT 'support'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Only the three original values are safe to roll back to; any
        // custom role in use would be truncated by reverting further.
        DB::statement("ALTER TABLE `admin_users` MODIFY `role` ENUM('super','sales','support') NOT NULL DEFAULT 'support'");
    }
};
