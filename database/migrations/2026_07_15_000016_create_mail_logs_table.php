<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release-1 transactional mail (MailService).
 * Every outbound email is logged with its final status so Ejaz can answer
 * "did the customer actually get the receipt?" without SSH-ing into logs.
 * A failed send is recorded, never thrown — email must not break billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_logs', function (Blueprint $t) {
            $t->id();
            $t->string('to_email')->index();
            $t->string('subject');
            $t->text('body')->nullable();
            $t->enum('status', ['sent', 'failed'])->default('sent');
            $t->text('error')->nullable()->comment('Exception message when status=failed');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
    }
};
