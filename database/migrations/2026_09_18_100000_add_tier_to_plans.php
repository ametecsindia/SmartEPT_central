<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard / Enforcer / Commander plans (18-Sep-2026): a plain string tier
 * marker distinguishing the three commercial tiers WITHOUT touching `code`
 * (several call sites already match on it — BuyController, provisionTrial())
 * and WITHOUT a hard ENUM, per the standing Ametecs rule that the application
 * layer owns the value set, never the column (see the admin_users.role ENUM
 * incident, 17-Sep-2026, for why that rule exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->string('tier', 20)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn('tier');
        });
    }
};
