<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('orders', 'quote_number')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->string('quote_number')->nullable()->unique()->after('number');
                $t->string('requested_by')->nullable()->after('quote_number');
            });
            DB::statement("ALTER TABLE orders MODIFY status ENUM('quote','created','paid','failed','refunded') NOT NULL DEFAULT 'created'");
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['quote_number', 'requested_by']);
        });
        DB::statement("ALTER TABLE orders MODIFY status ENUM('created','paid','failed','refunded') NOT NULL DEFAULT 'created'");
    }
};
