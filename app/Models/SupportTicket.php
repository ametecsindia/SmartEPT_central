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
}
