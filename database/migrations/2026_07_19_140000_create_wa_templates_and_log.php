<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp (Interakt) template registry + send log for SmartEPT Central.
 * Templates are created/approved in the Interakt dashboard; this is the single
 * registry + workflow tracker (draft → submitted → approved via a successful test).
 * WaService::templateNameFor() resolves the live name per purpose from approved rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wa_templates')) {
            Schema::create('wa_templates', function (Blueprint $t) {
                $t->id();
                $t->string('purpose', 20)->default('custom');   // welcome|payment|renewal|lead|otp|custom
                $t->string('name');                             // EXACT Interakt template name
                $t->string('language', 10)->default('en');
                $t->string('category', 20)->default('utility'); // utility|marketing|authentication
                $t->text('body')->nullable();                   // with {{1}}..{{n}}
                $t->text('sample_values')->nullable();          // comma-separated, for approval + tests
                $t->unsignedInteger('var_count')->default(0);
                $t->string('status', 20)->default('draft');     // draft|submitted|approved|rejected
                $t->timestamp('last_test_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('wa_log')) {
            Schema::create('wa_log', function (Blueprint $t) {
                $t->id();
                $t->string('mobile', 20)->nullable();
                $t->string('template')->nullable();
                $t->text('body_values')->nullable();
                $t->string('kind')->nullable();
                $t->string('status', 20)->default('failed');    // sent | failed
                $t->text('error')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_log');
        Schema::dropIfExists('wa_templates');
    }
};
