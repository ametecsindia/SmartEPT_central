<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pay-first /buy cart and the admin prospect-quote create a tenant in
 * status 'pending' (account exists, payment not yet made). But tenants.status
 * was ENUM('trial','active','suspended','expired','churned') — so on a
 * strict-mode MySQL the insert failed with 1265 "Data truncated for column
 * 'status'" and NO quote/order could be raised on live. Add 'pending' to the
 * allowed set. (Ejaz, 7-Aug-2026)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `tenants` MODIFY `status` "
            . "ENUM('pending','trial','active','suspended','expired','churned') "
            . "NOT NULL DEFAULT 'trial'");
    }

    public function down(): void
    {
        // Revert to the original set. Any rows still in 'pending' would be
        // truncated by MySQL on the way back — acceptable for a rollback.
        DB::statement("ALTER TABLE `tenants` MODIFY `status` "
            . "ENUM('trial','active','suspended','expired','churned') "
            . "NOT NULL DEFAULT 'trial'");
    }
};
