<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing v2 finalise (27-Jul-2026):
 *  - orders.po_number  : optional client purchase-order number on a deal.
 *  - orders.valid_until: quotation / pay-link validity (expired links refuse payment).
 *  - licences.pricing_model + legacy_baseline_inr: grandfather existing customers so
 *    their FIRST renewal migrates to the user-based v2 model with a price cap.
 *  - settings: pricing_grandfather_cap_pct (20) + quote_validity_days (7), admin-editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (! Schema::hasColumn('orders', 'po_number')) {
                $t->string('po_number')->nullable()->after('requested_by')
                    ->comment('Client purchase-order number for the deal (optional)');
            }
            if (! Schema::hasColumn('orders', 'valid_until')) {
                $t->date('valid_until')->nullable()->after('status')
                    ->comment('Quotation / pay-link validity — an expired link refuses payment');
            }
        });

        Schema::table('licences', function (Blueprint $t) {
            if (! Schema::hasColumn('licences', 'pricing_model')) {
                $t->string('pricing_model', 10)->default('v2')->after('billing')
                    ->comment('legacy = pre-v2 pricing (capped at next renewal); v2 = user-based');
            }
            if (! Schema::hasColumn('licences', 'legacy_baseline_inr')) {
                $t->decimal('legacy_baseline_inr', 12, 2)->nullable()->after('pricing_model')
                    ->comment('Pre-v2 ex-GST renewal subtotal — the grandfather cap is measured from this');
            }
        });

        // Grandfather EVERY existing non-trial licence: mark it legacy and snapshot the
        // ex-GST subtotal it last paid, so its next renewal is price-capped onto v2.
        // New licences default to 'v2'. Idempotent — only fills nulls / re-runs safely.
        DB::table('licences')->whereNotIn('kind', ['trial'])
            ->where('pricing_model', 'v2')
            ->update(['pricing_model' => 'legacy']);

        $legacyIds = DB::table('licences')->where('pricing_model', 'legacy')
            ->whereNull('legacy_baseline_inr')->pluck('id');
        foreach ($legacyIds as $lid) {
            $subtotal = DB::table('orders')
                ->where('licence_id', $lid)->where('status', 'paid')
                ->orderByDesc('paid_at')->value('subtotal');
            if ($subtotal !== null) {
                DB::table('licences')->where('id', $lid)
                    ->update(['legacy_baseline_inr' => $subtotal]);
            }
        }

        // Commercial knobs — admin-editable in Central → Settings. insertOrIgnore so a
        // re-run or a value the admin already changed is never overwritten.
        DB::table('settings')->insertOrIgnore([
            ['key' => 'pricing_grandfather_cap_pct', 'value' => '20'],
            ['key' => 'quote_validity_days', 'value' => '7'],
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['po_number', 'valid_until']);
        });
        Schema::table('licences', function (Blueprint $t) {
            $t->dropColumn(['pricing_model', 'legacy_baseline_inr']);
        });
        DB::table('settings')->whereIn('key', ['pricing_grandfather_cap_pct', 'quote_validity_days'])->delete();
    }
};
