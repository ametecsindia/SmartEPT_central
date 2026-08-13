<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom-pricing request queue (Ejaz, 13-Aug-2026).
 *
 * 1. orders.status ENUM → VARCHAR(20): the new 'request' state (a client- or
 *    admin-captured custom-quotation request, not yet priced) would be the
 *    FIFTH live ENUM casualty otherwise. Standing Ametecs lesson: prefer
 *    VARCHAR — the application layer owns the value set.
 * 2. orders.source VARCHAR(10): who created the row — 'admin' (console) or
 *    'client' (public /buy). NULL on historical rows (treated as admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `orders` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'created'");
        } else {
            // sqlite (tests): the legacy enum CHECK constraints are ENFORCED here —
            // the earlier "MySQL-only MODIFY" migrations left them in place, so
            // inserting orders.status='request' or tenants.status='pending'/'purged'
            // fails ONLY in tests. change() rebuilds the column as plain VARCHAR,
            // dropping the stale CHECK — same end state as live MySQL.
            Schema::table('orders', function (Blueprint $t) {
                $t->string('status', 20)->default('created')->change();
            });
            Schema::table('tenants', function (Blueprint $t) {
                $t->string('status', 20)->default('trial')->change();
            });
        }

        if (! Schema::hasColumn('orders', 'source')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('source', 10)->nullable()->after('status')
                    ->comment("who created it: admin | client (null = legacy/admin)");
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'source')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->dropColumn('source');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            // Full known set including 'request' so no existing row is truncated.
            DB::statement("ALTER TABLE `orders` MODIFY `status` "
                . "ENUM('quote','created','paid','failed','refunded','request') "
                . "NOT NULL DEFAULT 'created'");
        }
    }
};
