<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-update catalogue (Ejaz, 1-Sep-2026).
 *
 * DELIBERATELY separate from download_artifacts. That table serves the four
 * client-facing installer SLOTS resolved by slug/category+platform; this one
 * serves on-prem SERVERS asking "is there a newer build than mine?" and is
 * keyed on a version number. Mixing the two is what produced the 1-Sep slug
 * collision, so they stay apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_updates', function (Blueprint $table) {
            $table->id();
            $table->string('product', 40)->default('smartept');
            $table->string('version', 60);                  // 1.6.0 — what the on-prem server compares against
            $table->string('min_version', 60)->nullable();  // refuse to offer this build to anything older
            $table->string('channel', 20)->default('stable');
            $table->string('title', 160)->nullable();
            $table->text('notes')->nullable();              // release notes shown on the client's Licence screen
            $table->string('filename', 255)->nullable();    // lives in storage/app/updates
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();       // integrity check the updater re-runs after download
            $table->text('signature')->nullable();          // reserved for package signing
            $table->boolean('is_published')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->string('uploaded_by', 120)->nullable();
            $table->timestamps();

            $table->unique(['product', 'version']);
            $table->index(['product', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_updates');
    }
};
