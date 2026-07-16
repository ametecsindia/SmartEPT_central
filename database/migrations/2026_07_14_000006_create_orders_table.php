<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->string('quote_number')->nullable()->unique()->comment('EPT-Q-2026-27-07-0001 when raised as a quotation');
            $t->string('requested_by')->nullable()->comment('manager/employee who requested the purchase (SmartPRS pattern)');
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('licence_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->json('line_items')->comment('licence / setup fee / storage / services lines');
            $t->decimal('subtotal', 12, 2);
            $t->decimal('tax_amount', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->string('currency', 3)->default('INR');
            $t->enum('gateway', ['razorpay', 'stripe', 'manual'])->default('manual');
            $t->string('gateway_order_id')->nullable()->index();
            $t->string('gateway_payment_id')->nullable();
            $t->enum('status', ['quote', 'created', 'paid', 'failed', 'refunded'])->default('created');
            $t->string('manual_method')->nullable()->comment('NEFT / UPI / cheque / cash for manual gateway');
            $t->string('manual_reference')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
