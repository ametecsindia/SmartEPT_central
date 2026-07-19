<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client support tickets (Ejaz 20-Jul): a tenant raises an issue from the portal;
 * Ametecs staff reply + move it through open -> in_progress -> resolved -> closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('subject');
            $table->text('message');
            $table->string('category')->default('general');   // general | billing | technical
            $table->string('priority')->default('normal');     // low | normal | high
            $table->string('status')->default('open');         // open | in_progress | resolved | closed
            $table->text('admin_reply')->nullable();
            $table->unsignedBigInteger('replied_by')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->string('raised_by_name')->nullable();
            $table->string('raised_by_email')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
