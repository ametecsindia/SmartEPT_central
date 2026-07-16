<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For SmartEPT-Managed Cloud clients, Ametecs hosts their Admin Server. This
 * stores the URL of that hosted console so the client portal can link the
 * customer straight into their tracking dashboard / admin features. Null for
 * client-hosted clients (they run their own server).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('console_url')->nullable()->after('deployment');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('console_url');
        });
    }
};
