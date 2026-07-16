<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->date('date');
            $t->json('line_items');
            $t->decimal('subtotal', 12, 2);
            $t->decimal('gst_rate', 5, 2)->default(18);
            $t->decimal('gst_amount', 12, 2);
            $t->decimal('total', 12, 2);
            $t->string('currency', 3)->default('INR');
            $t->enum('status', ['draft', 'issued', 'paid', 'cancelled'])->default('issued');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
