<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landing CMS foundation (5-Aug-2026): section-wise editable landing page.
 * Non-destructive: creating these tables does NOT change the live page — the
 * '/' route keeps serving the static public/landing.html until rendering (Task 4)
 * is wired and verified byte-identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_sections', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();          // hero, hidden_loss, usps, ... ; __head__ = layout chrome
            $t->string('title')->default('');      // admin-facing label
            $t->string('type')->default('raw');    // raw | layout | (later: hero, icon-grid, ...)
            $t->longText('html')->nullable();      // verbatim HTML of the section (byte-identical render + advanced edit)
            $t->json('content')->nullable();       // structured fields (populated later, per type)
            $t->integer('sort')->default(0);
            $t->boolean('is_visible')->default(true);
            $t->boolean('is_layout')->default(false); // head/tail chrome — always rendered, hidden from the editor list
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('landing_media', function (Blueprint $t) {
            $t->id();
            $t->string('disk')->default('public');
            $t->string('path');
            $t->string('url');
            $t->string('kind')->default('image'); // image | icon-svg | logo
            $t->string('alt')->default('');
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('bytes')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('landing_versions', function (Blueprint $t) {
            $t->id();
            $t->longText('snapshot');               // JSON of sections + seo at publish time
            $t->string('note')->default('');
            $t->unsignedBigInteger('published_by')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // Non-destructive rollback order.
        Schema::dropIfExists('landing_versions');
        Schema::dropIfExists('landing_media');
        Schema::dropIfExists('landing_sections');
    }
};
