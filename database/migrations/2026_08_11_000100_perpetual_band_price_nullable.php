<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Progressive lifetime pricing (11-Aug-2026): price_inr now means "one-time
 * price AT the band's max_users milestone" and the open-ended Custom Quote
 * band stores price_inr = NULL — an explicit "no automatic price" marker so
 * ₹0 can never be mistaken for (or sold as) a free lifetime licence.
 *
 * Safe & non-destructive: only relaxes the column to nullable and converts
 * any existing open-ended band's 0 price to NULL. No pricing rows deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_perpetual_bands', function (Blueprint $t) {
            $t->unsignedInteger('price_inr')->nullable()
                ->comment('one-time lifetime price AT the max_users milestone; NULL = custom quote (open-ended band)')
                ->change();
        });

        // An open-ended band priced 0 was the old "custom" convention — make it explicit.
        DB::table('plan_perpetual_bands')->whereNull('max_users')->where('price_inr', 0)
            ->update(['price_inr' => null]);
    }

    public function down(): void
    {
        DB::table('plan_perpetual_bands')->whereNull('price_inr')->update(['price_inr' => 0]);

        Schema::table('plan_perpetual_bands', function (Blueprint $t) {
            $t->unsignedInteger('price_inr')->nullable(false)
                ->comment('one-time lifetime licence price for this capacity')
                ->change();
        });
    }
};
