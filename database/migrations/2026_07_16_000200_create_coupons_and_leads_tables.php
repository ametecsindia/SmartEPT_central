<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R3-7 sales ops (SmartPRS /super parity): lead pipeline + discount coupons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('city')->nullable();
            $table->unsignedInteger('devices_interested')->nullable();
            $table->string('source', 64)->default('website');   // website | whatsapp | referral | manual | campaign
            $table->text('message')->nullable();
            // NEW → CONTACTED → DEMO_SCHEDULED → QUOTED → WON | LOST
            $table->string('status', 32)->default('NEW');
            $table->text('notes')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->string('assigned_to')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete(); // set on WON
            $table->timestamps();

            $table->index(['status', 'follow_up_at']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description')->nullable();
            $table->enum('type', ['percent', 'flat'])->default('percent');
            $table->decimal('value', 10, 2);                     // 10 (%) or 5000 (₹)
            $table->unsignedInteger('max_uses')->nullable();     // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('min_devices')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('leads');
    }
};
