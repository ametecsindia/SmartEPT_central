<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release-1 GST-compliant invoicing.
 * The existing gst_rate/gst_amount columns stay untouched (totals and every
 * existing test remain identical) — cgst/sgst/igst are a BREAKDOWN of the same
 * 18% tax, decided by seller state vs buyer state at invoice time.
 * buyer_gstin and place_of_supply are SNAPSHOTS: a tenant editing their
 * profile later must never rewrite an issued tax document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('cgst', 12, 2)->default(0)->after('gst_amount');
            $t->decimal('sgst', 12, 2)->default(0)->after('cgst');
            $t->decimal('igst', 12, 2)->default(0)->after('sgst');
            $t->string('place_of_supply')->nullable()->after('igst')
                ->comment('"[code]-[state name]" snapshot, e.g. 36-Telangana');
            $t->string('buyer_gstin', 15)->nullable()->after('place_of_supply');
            $t->string('sac_code', 10)->default('997331')->after('buyer_gstin')
                ->comment('997331 = licensing of software (SaaS), the SmartEPT service');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['cgst', 'sgst', 'igst', 'place_of_supply', 'buyer_gstin', 'sac_code']);
        });
    }
};
