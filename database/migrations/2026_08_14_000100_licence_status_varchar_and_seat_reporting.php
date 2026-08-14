<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 14-Aug-2026 licence hardening (Ejaz's 4 findings).
 *
 * 1. ENUM STRIKE FIVE prevention — `licences.status` and `licences.kind` become
 *    VARCHAR(20). Adding the new 'superseded' status to an ENUM would silently
 *    truncate on MySQL exactly like tenants.status / action_on_blocked did.
 * 2. Seat REPORTING columns — the client console now tells Central, on its daily
 *    phone-home, how many login users / employees / devices it actually has.
 *    Central could previously only see devices that called device/activate, so a
 *    live 14-user client displayed as "0/25".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `licences` MODIFY `status` VARCHAR(20) NOT NULL DEFAULT 'active'");
            DB::statement("ALTER TABLE `licences` MODIFY `kind` VARCHAR(20) NOT NULL DEFAULT 'subscription'");
        } else {
            // sqlite (tests): enum CHECK constraints are actually ENFORCED here, so a
            // MySQL-only MODIFY would leave 'superseded' failing in tests alone.
            // change() rebuilds the columns as plain VARCHAR — same end state as live.
            Schema::table('licences', function (Blueprint $t) {
                $t->string('status', 20)->default('active')->change();
                $t->string('kind', 20)->default('subscription')->change();
            });
        }

        Schema::table('licences', function (Blueprint $t) {
            if (! Schema::hasColumn('licences', 'reported_users')) {
                $t->unsignedInteger('reported_users')->nullable()->after('renewal_device_limit')
                    ->comment('login accounts reported by the client console on its daily check-in');
            }
            if (! Schema::hasColumn('licences', 'reported_employees')) {
                $t->unsignedInteger('reported_employees')->nullable()->after('reported_users')
                    ->comment('active employees reported by the client console');
            }
            if (! Schema::hasColumn('licences', 'reported_devices')) {
                $t->unsignedInteger('reported_devices')->nullable()->after('reported_employees')
                    ->comment('bound agent devices reported by the client console');
            }
            if (! Schema::hasColumn('licences', 'reported_at')) {
                $t->timestamp('reported_at')->nullable()->after('reported_devices');
            }
        });


        // BACKFILL (finding 1.1): clients that already carry a stale 'active'
        // trial alongside a paid licence — e.g. Skill Dunya on the live board —
        // are closed here, so the fix applies to today's data and not only to
        // licences issued from now on.
        $paidTenants = DB::table('licences')
            ->where('status', 'active')
            ->whereIn('kind', ['subscription', 'perpetual'])
            ->distinct()->pluck('tenant_id');

        if ($paidTenants->isNotEmpty()) {
            DB::table('licences')
                ->whereIn('tenant_id', $paidTenants)
                ->where('kind', 'trial')
                ->where('status', 'active')
                ->update(['status' => 'superseded', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('licences', function (Blueprint $t) {
            foreach (['reported_users', 'reported_employees', 'reported_devices', 'reported_at'] as $c) {
                if (Schema::hasColumn('licences', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
