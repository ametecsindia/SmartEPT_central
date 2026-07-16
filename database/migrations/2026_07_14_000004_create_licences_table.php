<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licences', function (Blueprint $t) {
            $t->id();
            $t->string('key', 64)->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained();
            $t->enum('kind', ['trial', 'subscription', 'perpetual'])->default('subscription');
            $t->enum('billing', ['annual', 'monthly'])->default('annual');
            $t->enum('deployment', ['client_hosted', 'cloud'])->default('client_hosted');
            $t->unsignedInteger('device_limit')->default(10);
            $t->json('features')->nullable()->comment('effective entitlements = plan features + addons');
            $t->json('addons')->nullable();
            $t->enum('status', ['active', 'suspended', 'revoked', 'expired'])->default('active');
            $t->date('starts_at');
            $t->date('expires_at')->nullable()->comment('null for perpetual');
            $t->unsignedSmallInteger('grace_days')->default(7);
            $t->date('amc_expires_at')->nullable()->comment('perpetual only - gates updates/support');
            $t->string('server_fingerprint')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('last_validated_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licences');
    }
};
