<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix (23-Jul-2026) — Central bug #2 (support tickets).
 *
 * Before: a single `admin_reply` column on support_tickets was OVERWRITTEN on
 * every update, so (B) editing an update erased the previous reply, and (C)
 * there was no history/timeline and no record of who responded.
 *
 * After: an append-only thread. Every client message, staff reply, status
 * change and internal note is its own immutable row with its author and time,
 * so the full conversation and timeline is preserved and attributable.
 *
 * Existing tickets are backfilled so nothing already on record is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            // who wrote it: client | admin | system
            $table->string('author_type', 20)->default('admin');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name')->nullable();   // captured at write time so it survives user renames/deletes
            // what happened: message | reply | status_change | note
            $table->string('event', 20)->default('reply');
            $table->text('body')->nullable();             // status_change rows may carry no text
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->boolean('emailed')->default(false);   // was the client notified of this row
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
        });

        // ---- Backfill: preserve every existing ticket's original message + reply ----
        foreach (DB::table('support_tickets')->orderBy('id')->get() as $t) {
            // 1. The client's opening message.
            DB::table('support_ticket_messages')->insert([
                'ticket_id'   => $t->id,
                'author_type' => 'client',
                'author_id'   => null,
                'author_name' => $t->raised_by_name,
                'event'       => 'message',
                'body'        => $t->message,
                'emailed'     => false,
                'created_at'  => $t->created_at,
                'updated_at'  => $t->created_at,
            ]);

            // 2. The single stored admin reply, if any (kept as the first reply in the thread).
            if (! empty($t->admin_reply)) {
                DB::table('support_ticket_messages')->insert([
                    'ticket_id'   => $t->id,
                    'author_type' => 'admin',
                    'author_id'   => $t->replied_by,
                    'author_name' => null,
                    'event'       => 'reply',
                    'body'        => $t->admin_reply,
                    'emailed'     => true,
                    'created_at'  => $t->replied_at ?? $t->updated_at ?? $t->created_at,
                    'updated_at'  => $t->replied_at ?? $t->updated_at ?? $t->created_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
