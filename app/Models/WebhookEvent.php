<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['gateway','event_type','event_id','payload','processed','error'];
    protected $casts = ['payload'=>'array','processed'=>'boolean'];
}
