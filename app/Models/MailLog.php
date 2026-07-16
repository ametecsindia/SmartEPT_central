<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailLog extends Model
{
    protected $fillable = ['to_email', 'subject', 'body', 'status', 'error'];
}
