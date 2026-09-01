<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill (31-Aug-2026) — the other half of the 23-Jul thread migration.
 *
 * 2026_07_23_000800 moved support tickets onto an append-only thread and
 * backfilled every ticket that existed at that moment. But the CREATE path
 * (Client\PortalApiController::createTicket) was never wired to write the
 * opening row, so every ticket raised since then has no client 'message' row —
 * the admin desk renders its timeline from support_ticket_messages alone and
 * showed "No messages yet", with the client's description nowhere on the ticket.
 *
 * createTicket() now writes that row. This repairs the tickets already on
 * record. Idempotent: only tickets with no client 'message' row are touched,
 * and the row is inserted at the ticket's own created_at so it sorts first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $withOpening = DB::table('support_ticket_messages')
            ->where('event', 'message')->where('author_type', 'client')
            ->distinct()->pluck('ticket_id');

        DB::table('support_tickets')
            ->whereNotIn('id', $withOpening)
            ->orderBy('id')
            ->each(function ($t) {
                if (trim((string) $t->message) === '') {
                    return; // nothing to restore
                }
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
            });
    }

    public function down(): void
    {
        // Nothing to undo — these rows restore data the tickets already carry
        // in support_tickets.message. Dropping them would only re-break the view.
    }
};
