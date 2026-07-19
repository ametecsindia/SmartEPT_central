<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EPT-27 (cloud multi-tenancy) — per-tenant storage governance.
 * plans.storage_gb = GB included per plan; tenants.storage_gb_override = per-client
 * override. Usage (storage_usage.gb_used, fed from the mounted GCS bucket sizes) is
 * metered against this quota; the console flags WARN >= 90% and OVER >= 100%.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'storage_gb')) {
            Schema::table('plans', function (Blueprint $t) {
                $t->unsignedInteger('storage_gb')->default(50)->after('min_devices')
                    ->comment('Cloud storage GB included in this plan');
            });
            DB::table('plans')->where('code', 'core')->update(['storage_gb' => 50]);
            DB::table('plans')->where('code', 'professional')->update(['storage_gb' => 100]);
            DB::table('plans')->where('code', 'enterprise')->update(['storage_gb' => 250]);
        }

        if (! Schema::hasColumn('tenants', 'storage_gb_override')) {
            Schema::table('tenants', function (Blueprint $t) {
                $t->unsignedInteger('storage_gb_override')->nullable()->after('purge_after')
                    ->comment('Per-client storage GB quota override (null = use the plan quota)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'storage_gb')) {
            Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('storage_gb'));
        }
        if (Schema::hasColumn('tenants', 'storage_gb_override')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('storage_gb_override'));
        }
    }
};
