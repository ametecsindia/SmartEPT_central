<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SupportTicket;
use App\Services\MailService;
use Illuminate\Http\Request;

/**
 * Ametecs-side support desk (Ejaz 20-Jul): list tenant tickets, reply, and move
 * each through open -> in_progress -> resolved -> closed. A reply emails the client.
 */
class SupportTicketController extends Controller
{
    /** GET /admin/api/tickets?status= */
    public function index(Request $request)
    {
        $q = SupportTicket::with('tenant:id,company_name,email,phone')->latest('id');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }

        $rows = $q->limit(300)->get()->map(fn (SupportTicket $t) => [
            'id'           => $t->id,
            'tenant'       => $t->tenant?->company_name,
            'tenant_id'    => $t->tenant_id,
            'subject'      => $t->subject,
            'message'      => $t->message,
            'category'     => $t->category,
            'status'       => $t->status,
            'admin_reply'  => $t->admin_reply,
            'raised_by'    => $t->raised_by_name,
            'raised_email' => $t->raised_by_email,
            'phone'        => $t->tenant?->phone,
            'created_at'   => optional($t->created_at)->toDateTimeString(),
            'replied_at'   => optional($t->replied_at)->toDateTimeString(),
        ]);

        $counts = SupportTicket::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => $rows, 'counts' => $counts]);
    }

    /** PUT /admin/api/tickets/{ticket} — set status and optionally reply (emails the client). */
    public function update(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
            'reply'  => ['nullable', 'string', 'max:4000'],
        ]);

        $ticket->status = $data['status'];
        $sendReply = filled($data['reply'] ?? null);
        if ($sendReply) {
            $ticket->admin_reply = $data['reply'];
            $ticket->replied_by = auth('admin')->id();
            $ticket->replied_at = now();
        }
        $ticket->save();

        AuditLog::write('ticket.updated', $ticket, ['status' => $ticket->status, 'replied' => $sendReply]);

        if ($sendReply && $ticket->raised_by_email) {
            try {
                app(MailService::class)->send(
                    $ticket->raised_by_email,
                    'Re: your SmartEPT support ticket #' . $ticket->id . ' — ' . $ticket->subject,
                    "Hello,\n\nRegarding your ticket \"{$ticket->subject}\":\n\n{$ticket->admin_reply}\n\nStatus: "
                    . strtoupper($ticket->status) . "\n\nReply to this email, or raise a new ticket in your client portal if you need anything else." . MailService::signature()
                );
            } catch (\Throwable $e) {
                // ignore mail failures
            }
        }

        return response()->json(['ok' => true]);
    }
}
