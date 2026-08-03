<?php

use App\Models\Plan;
use Database\Seeders\PricingV2Seeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Repair / bootstrap the pricing model v2 DATA (03-Aug-2026).
 *
 * The v2 migrations (26-Jul) created the plan_volume_tiers / plan_perpetual_bands
 * TABLES, but the single all-features "smartept" PLAN row is only created by
 * PricingV2Seeder -- which was never wired into DatabaseSeeder nor any migration.
 * On installs that ran migrate.bat but not that seeder, no plan with code
 * "smartept" exists, so EVERY quote/order fails: the admin New-Order modal shows
 * "The selected plan code is invalid." and the client portal Pay-now / Raise-
 * quotation buttons return "Request failed" (a 404 on the missing plan).
 *
 * This migration self-heals such installs by running the (idempotent) seeder when
 * the smartept plan is absent. Safe everywhere; a no-op where it already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Plan::where('code', 'smartept')->exists()) {
            (new PricingV2Seeder())->run();
        }
    }

    public function down(): void
    {
        // Non-destructive: pricing data is business-critical; never dropped on rollback.
    }
};
