<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0 D5 — refunds / credit-notes. A refund is a NEGATIVE row in the existing
 * order_payments ledger (received() drops automatically). This adds the GST-style
 * credit-note number to that row so each refund prints a numbered credit note.
 * Nullable + unique: existing payment rows keep NULL (MySQL allows many NULLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_payments', 'credit_note_number')) {
            Schema::table('order_payments', function (Blueprint $t) {
                $t->string('credit_note_number')->nullable()->after('reference')
                    ->comment('Credit-note number for refund rows (negative amount); GST-style FY series.');
                $t->unique('credit_note_number', 'order_payments_cn_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_payments', 'credit_note_number')) {
            Schema::table('order_payments', function (Blueprint $t) {
                $t->dropUnique('order_payments_cn_unique');
                $t->dropColumn('credit_note_number');
            });
        }
    }
};
