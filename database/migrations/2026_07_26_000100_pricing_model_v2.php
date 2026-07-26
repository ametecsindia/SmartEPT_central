<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing model v2 (26-Jul-2026): single all-features product, priced purely by
 * user scale. Cloud = rental (per-user/month bands, reusing plan_volume_tiers as
 * USER bands); Perpetual = one-time by licensed-user capacity (this table). All
 * additive & non-destructive — old plans stay in the DB (deactivated by the seeder).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('plan_perpetual_bands')) {
            Schema::create('plan_perpetual_bands', function (Blueprint $t) {
                $t->id();
                $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $t->unsignedInteger('min_users');
                $t->unsignedInteger('max_users')->nullable()->comment('null = open-ended / custom above');
                $t->unsignedInteger('price_inr')->comment('one-time lifetime licence price for this capacity');
                $t->unsignedTinyInteger('sort')->default(0);
                $t->timestamps();
                $t->index(['plan_id', 'min_users']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_perpetual_bands');
    }
};
