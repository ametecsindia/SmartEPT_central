<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->id();
            $t->enum('gateway', ['razorpay', 'stripe']);
            $t->string('event_type');
            $t->string('event_id')->nullable()->index();
            $t->json('payload');
            $t->boolean('processed')->default(false);
            $t->text('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
