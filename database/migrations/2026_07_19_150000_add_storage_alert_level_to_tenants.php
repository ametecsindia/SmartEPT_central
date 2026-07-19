<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** EPT-27 enforcement: remember the last storage alert level per tenant so the
 *  90%/100% email fires ONCE per escalation (not on every phone-home). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'storage_alert_level')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->string('storage_alert_level', 10)->default('OK')->after('storage_gb_override'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'storage_alert_level')) {
            Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('storage_alert_level'));
        }
    }
};
