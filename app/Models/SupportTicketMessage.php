<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One immutable row in a support ticket's thread — a client message, a staff
 * reply, a status change or an internal note. Rows are only ever appended, so
 * the full history and who-said-what timeline is always preserved (Central
 * bug #2, 23-Jul-2026).
 */
class SupportTicketMessage extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['emailed' => 'boolean'];

    public const EVENTS = ['message', 'reply', 'status_change', 'note'];

    public function ticket()
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
