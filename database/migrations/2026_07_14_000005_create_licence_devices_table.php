<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licence_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('licence_id')->constrained()->cascadeOnDelete();
            $t->string('device_uid');
            $t->string('hostname')->nullable();
            $t->enum('status', ['active', 'deactivated'])->default('active');
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('deactivated_at')->nullable();
            $t->timestamps();
            $t->unique(['licence_id', 'device_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licence_devices');
    }
};
