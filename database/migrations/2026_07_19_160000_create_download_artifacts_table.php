<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Managed installer catalogue (Ejaz 19-Jul): super-admin uploads/publishes the
 * SmartEPT Employee Agent for Windows / Mac / Linux and the Admin Server
 * installer, each with a version, description and release notes. The client
 * portal's Install & Downloads page reads the PUBLISHED rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();          // agent-windows, agent-mac, agent-linux, server-windows
            $table->string('category');                // agent | server
            $table->string('platform')->nullable();    // windows | mac | linux
            $table->string('title');
            $table->string('version')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();         // release notes / install notes
            $table->string('filename')->nullable();    // file in storage/app/downloads/
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('sort')->default(0);
            $table->string('uploaded_by')->nullable();
            $table->timestamps();
        });

        // Seed the four standard slots (unpublished until a file is attached).
        $now = now();
        $rows = [
            ['slug' => 'agent-windows',  'category' => 'agent',  'platform' => 'windows', 'title' => 'SmartEPT Employee Agent — Windows', 'sort' => 10],
            ['slug' => 'agent-mac',      'category' => 'agent',  'platform' => 'mac',     'title' => 'SmartEPT Employee Agent — macOS',  'sort' => 20],
            ['slug' => 'agent-linux',    'category' => 'agent',  'platform' => 'linux',   'title' => 'SmartEPT Employee Agent — Linux',  'sort' => 30],
            ['slug' => 'server-windows', 'category' => 'server', 'platform' => 'windows', 'title' => 'SmartEPT Admin Server — Windows',   'sort' => 40],
        ];
        foreach ($rows as $r) {
            \Illuminate\Support\Facades\DB::table('download_artifacts')->insert($r + [
                'is_published' => false,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('download_artifacts');
    }
};
