<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Download history + anti-abuse ledger (Ejaz 19-Jul): one row per successful
 * client installer download. Powers the per-client daily/monthly quotas and the
 * super-admin download log (who downloaded what, when, and how many in total).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('tenant_name')->nullable();   // snapshot of the company name
            $table->string('artifact_slug')->index();
            $table->string('artifact_title')->nullable();
            $table->string('platform')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'artifact_slug', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_logs');
    }
};
