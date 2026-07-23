<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Services\MailService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ametecs-side support desk (Ejaz 20-Jul): list tenant tickets, reply, and move
 * each through open -> in_progress -> resolved -> closed.
 *
 * 23-Jul-2026 (Central bug #2): replies are now APPENDED to a thread instead of
 * overwriting a single field, so nothing is ever erased; every reply and status
 * change records who did it and when; and all times are shown in the business
 * timezone (IST by default), not the server's UTC.
 */
class SupportTicketController extends Controller
{
    /** The timezone tickets are displayed in — India by default, admin-configurable. */
    private function tz(): string
    {
        return Setting::get('display_timezone', 'Asia/Kolkata') ?: 'Asia/Kolkata';
    }

    /** Format a stored (UTC) timestamp in the business timezone, e.g. "23 Jul 2026, 08:15 PM". */
    private function local($dt): ?string
    {
        if (! $dt) {
            return null;
        }

        return Carbon::parse($dt)->timezone($this->tz())->format('d M Y, h:i A');
    }

    /** GET /admin/api/tickets?status= */
    public function index(Request $request)
    {
        $q = SupportTicket::with('tenant:id,company_name,email,phone')
            ->withCount('messages')
            ->latest('id');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }

        $rows = $q->limit(300)->get()->map(fn (SupportTicket $t) => [
            'id'             => $t->id,
            'tenant'         => $t->tenant?->company_name,
            'tenant_id'      => $t->tenant_id,
            'subject'        => $t->subject,
            'message'        => $t->message,
            'category'       => $t->category,
            'status'         => $t->status,
            'admin_reply'    => $t->admin_reply,
            'raised_by'      => $t->raised_by_name,
            'raised_email'   => $t->raised_by_email,
            'phone'          => $t->tenant?->phone,
            'messages_count' => $t->messages_count,
            // Machine values kept for any existing logic…
            'created_at'     => optional($t->created_at)->toDateTimeString(),
            'replied_at'     => optional($t->replied_at)->toDateTimeString(),
            // …plus IST-formatted values for display (bug #2A).
            'created_at_h'   => $this->local($t->created_at),
            'replied_at_h'   => $this->local($t->replied_at),
        ]);

        $counts = SupportTicket::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return response()->json(['data' => $rows, 'counts' => $counts, 'tz' => $this->tz()]);
    }

    /**
     * GET /admin/api/tickets/{ticket} — the full conversation thread & timeline
     * (client message, every staff reply, and each status change) with the
     * author and IST time of each entry (bug #2B/#2C).
     */
    public function show(SupportTicket $ticket)
    {
        $ticket->load(['tenant:id,company_name,email,phone', 'messages']);

        // Resolve admin author names once (backfilled rows may only have an id).
        $adminNames = AdminUser::whereIn('id', $ticket->messages->pluck('author_id')->filter()->unique())
            ->pluck('name', 'id');

        $timeline = $ticket->messages->map(function (SupportTicketMessage $m) use ($adminNames) {
            $name = $m->author_name
                ?: ($m->author_type === 'admin' ? ($adminNames[$m->author_id] ?? 'Ametecs staff') : null)
                ?: ($m->author_type === 'client' ? 'Client' : 'System');

            return [
                'id'          => $m->id,
                'author_type' => $m->author_type,
                'author_name' => $name,
                'event'       => $m->event,
                'body'        => $m->body,
                'old_status'  => $m->old_status,
                'new_status'  => $m->new_status,
                'at'          => optional($m->created_at)->toDateTimeString(),
                'at_h'        => $this->local($m->created_at),
            ];
        });

        return response()->json([
            'ticket' => [
                'id'           => $ticket->id,
                'subject'      => $ticket->subject,
                'category'     => $ticket->category,
                'status'       => $ticket->status,
                'tenant'       => $ticket->tenant?->company_name,
                'raised_by'    => $ticket->raised_by_name,
                'raised_email' => $ticket->raised_by_email,
                'phone'        => $ticket->tenant?->phone,
                'created_at_h' => $this->local($ticket->created_at),
            ],
            'timeline' => $timeline,
            'tz'       => $this->tz(),
        ]);
    }

    /**
     * PUT /admin/api/tickets/{ticket} — add a reply and/or change status.
     * A reply is APPENDED to the thread (previous replies are never touched) and
     * emailed to the client; a status change is recorded as its own timeline entry.
     */
    public function update(Request $request, SupportTicket $ticket)
    {
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
            'reply'  => ['nullable', 'string', 'max:4000'],
        ]);

        $admin     = auth('admin')->user();
        $adminName = $admin?->name;
        $oldStatus = $ticket->status;
        $sendReply = filled($data['reply'] ?? null);

        // 1. Append the reply as a new immutable thread row (never overwrite).
        if ($sendReply) {
            SupportTicketMessage::create([
                'ticket_id'   => $ticket->id,
                'author_type' => 'admin',
                'author_id'   => $admin?->id,
                'author_name' => $adminName,
                'event'       => 'reply',
                'body'        => $data['reply'],
                'emailed'     => (bool) $ticket->raised_by_email,
            ]);

            // Keep a denormalised "latest reply" for the list preview / older code.
            $ticket->admin_reply = $data['reply'];
            $ticket->replied_by  = $admin?->id;
            $ticket->replied_at  = now();
        }

        // 2. Record a status change as its own timeline entry.
        if ($data['status'] !== $oldStatus) {
            SupportTicketMessage::create([
                'ticket_id'   => $ticket->id,
                'author_type' => 'admin',
                'author_id'   => $admin?->id,
                'author_name' => $adminName,
                'event'       => 'status_change',
                'body'        => null,
                'old_status'  => $oldStatus,
                'new_status'  => $data['status'],
            ]);
            $ticket->status = $data['status'];
        }

        $ticket->save();

        AuditLog::write('ticket.updated', $ticket, [
            'status'      => $ticket->status,
            'from_status' => $oldStatus,
            'replied'     => $sendReply,
            'by'          => $adminName,
        ]);

        if ($sendReply && $ticket->raised_by_email) {
            try {
                app(MailService::class)->send(
                    $ticket->raised_by_email,
                    'Re: your SmartEPT support ticket #' . $ticket->id . ' — ' . $ticket->subject,
                    "Hello,\n\nRegarding your ticket \"{$ticket->subject}\":\n\n{$data['reply']}\n\nStatus: "
                    . strtoupper($ticket->status) . "\n\nReply to this email, or raise a new ticket in your client portal if you need anything else." . MailService::signature()
                );
            } catch (\Throwable $e) {
                // ignore mail failures
            }
        }

        return response()->json(['ok' => true]);
    }
}
