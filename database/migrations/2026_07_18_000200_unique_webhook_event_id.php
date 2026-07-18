<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R5 EPT-16: back the webhook idempotency guard with a UNIQUE (gateway, event_id)
 * index. De-dupe any existing rows first (keep earliest) so the index builds
 * cleanly; NULL event_ids (e.g. Razorpay deliveries missing the header) are left
 * as-is and allowed to repeat under the unique index (multiple NULLs permitted).
 */
return new class extends Migration
{
    public function up(): void
    {
        $dupes = DB::table('webhook_events')
            ->whereNotNull('event_id')
            ->select('gateway', 'event_id', DB::raw('MIN(id) as keep_id'))
            ->groupBy('gateway', 'event_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($dupes as $d) {
            DB::table('webhook_events')
                ->where('gateway', $d->gateway)->where('event_id', $d->event_id)
                ->where('id', '!=', $d->keep_id)->delete();
        }

        Schema::table('webhook_events', function (Blueprint $t) {
            $t->dropIndex(['event_id']);
            $t->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $t) {
            $t->dropUnique(['gateway', 'event_id']);
            $t->index('event_id');
        });
    }
};
