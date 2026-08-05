<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable per-tenant console slug (admin.smartept.com/<slug>). Blank = auto
 * from the company name at provisioning. Unique so two tenants never share a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'console_slug')) {
                $table->string('console_slug', 40)->nullable()->unique()->after('console_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'console_slug')) {
                $table->dropUnique(['console_slug']);
                $table->dropColumn('console_slug');
            }
        });
    }
};
