<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release-1 GST billing profile (customer side).
 * tenants.gstin already exists since the first migration — here we add the two
 * missing pieces the tax invoice needs: the buyer's GST state code (drives the
 * CGST/SGST vs IGST decision + place of supply) and a dedicated billing
 * address (the operational `address` field often holds an office/site address
 * that should not necessarily appear on tax documents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('state_code', 2)->nullable()->after('gstin')
                ->comment('GST state code (e.g. 36 = Telangana). Drives CGST/SGST vs IGST.');
            $t->text('billing_address')->nullable()->after('state_code')
                ->comment('Address printed on tax invoices; falls back to address when empty.');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['state_code', 'billing_address']);
        });
    }
};
