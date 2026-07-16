<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('company_name');
            $t->string('contact_name')->nullable();
            $t->string('email');
            $t->string('phone')->nullable();
            $t->string('gstin')->nullable();
            $t->text('address')->nullable();
            $t->string('country', 2)->default('IN');
            $t->string('currency', 3)->default('INR');
            $t->enum('deployment', ['client_hosted', 'cloud'])->default('client_hosted');
            $t->enum('status', ['trial', 'active', 'suspended', 'expired', 'churned'])->default('trial');
            $t->boolean('ecosystem_customer')->default(false)->comment('Existing SmartDCM/SmartPRS customer - eligible for intro rate');
            $t->boolean('setup_fee_paid')->default(false)->comment('One-time setup & onboarding fee charged on first invoice');
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('purge_after')->nullable()->comment('Trial data purge deadline after expiry grace');
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
