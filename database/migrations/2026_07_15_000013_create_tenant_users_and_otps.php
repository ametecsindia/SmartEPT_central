<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — /client tenant portal.
 * tenant_users = the customer-side logins (owner/manager of a tenant company).
 * client_otps  = one-time codes for signup verification & password reset (email channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone')->nullable();
            $t->string('password');
            $t->string('role')->default('owner')->comment('owner = full self-service in /client');
            $t->boolean('active')->default(true);
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('client_otps', function (Blueprint $t) {
            $t->id();
            $t->string('email')->index();
            $t->string('purpose', 20)->comment('signup | reset');
            $t->string('code_hash', 64);
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_otps');
        Schema::dropIfExists('tenant_users');
    }
};
