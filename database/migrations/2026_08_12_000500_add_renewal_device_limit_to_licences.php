<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downgrade-at-renewal (Ejaz, 12-Aug-2026): a Cloud subscription can SCHEDULE a
 * seat reduction for its next renewal — mid-period reductions stay impossible
 * (growth uses the pro-rata upgrade). NULL = nothing scheduled. The renewal
 * order bills this count and provisioning applies + clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->unsignedInteger('renewal_device_limit')->nullable()->after('device_limit');
        });
    }

    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn('renewal_device_limit');
        });
    }
};
