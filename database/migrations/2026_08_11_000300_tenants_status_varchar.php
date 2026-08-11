<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ENUM strike three (11-Aug-2026): RunDunning::enforcePurgeWindows() writes
 * tenants.status = 'purged', but the column was
 * ENUM('pending','trial','active','suspended','expired','churned') — 'purged'
 * missing → strict-MySQL 1265 "Data truncated" killed the hourly
 * smartept:dunning run on live (tenant #7, every hour on the hour).
 *
 * Standing Ametecs lesson: prefer VARCHAR over ENUM — this is the third live
 * path an ENUM has broken ('pending' 7-Aug, product usage-category 7-Aug).
 * Convert once and for all; the application layer owns the value set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return; // sqlite (tests) stores enums as TEXT already
        }

        DB::statement("ALTER TABLE `tenants` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'trial'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Full known set including 'purged' so no existing row is truncated.
        DB::statement("ALTER TABLE `tenants` MODIFY `status` "
            . "ENUM('pending','trial','active','suspended','expired','churned','purged') "
            . "NOT NULL DEFAULT 'trial'");
    }
};
