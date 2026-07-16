<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->unsignedInteger('inr_annual')->comment('per device per month, billed annually');
            $t->unsignedInteger('inr_monthly');
            $t->decimal('usd_annual', 8, 2)->default(0);
            $t->decimal('usd_monthly', 8, 2)->default(0);
            $t->unsignedInteger('perpetual_device_inr')->default(0);
            $t->unsignedInteger('perpetual_server_inr')->default(0);
            $t->unsignedInteger('min_devices')->default(10);
            $t->json('features')->comment('entitlement flags map');
            $t->unsignedTinyInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('plan_volume_tiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('min_devices');
            $t->unsignedInteger('max_devices')->nullable();
            $t->unsignedInteger('rate_inr_annual')->comment('per device per month at this volume');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_volume_tiers');
        Schema::dropIfExists('plans');
    }
};
