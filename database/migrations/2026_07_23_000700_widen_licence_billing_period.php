<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix (23-Jul-2026) — Central bug #1 & #5.
 *
 * The `licences.billing` column was created as enum('annual','monthly') only.
 * So issuing or editing a licence with a QUARTERLY or HALF-YEARLY (6-month)
 * period was rejected by MySQL with a "Data truncated for column 'billing'"
 * error. The same column also broke "convert Trial to Paid" on those periods,
 * because the licence is issued during provisioning on the FIRST payment
 * (including a Partial payment) — so the error surfaced at the payment step.
 *
 * The application layer (validation rules, LicenceService::billingMonths(),
 * dropdowns) already supports all four periods. We simply widen the column to
 * a VARCHAR so every current and future period is accepted with no further
 * schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A raw MODIFY is required to change an ENUM (Laravel/DBAL cannot alter it).
        DB::statement("ALTER TABLE `licences` MODIFY `billing` VARCHAR(20) NOT NULL DEFAULT 'annual'");
    }

    public function down(): void
    {
        // Fold the newly-allowed values back into the original two so the
        // narrower enum can be restored without a data-truncation error.
        DB::table('licences')->whereIn('billing', ['half_yearly', 'quarterly'])->update(['billing' => 'annual']);
        DB::statement("ALTER TABLE `licences` MODIFY `billing` ENUM('annual','monthly') NOT NULL DEFAULT 'annual'");
    }
};
