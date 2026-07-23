<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['replied_at' => 'datetime'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Append-only conversation thread (Central bug #2). Oldest first. */
    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('id');
    }
}
