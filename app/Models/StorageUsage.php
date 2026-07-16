<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageUsage extends Model
{
    protected $table = 'storage_usage';
    protected $fillable = ['tenant_id','date','gb_used'];
    protected $casts = ['date'=>'date','gb_used'=>'float'];
    public function tenant() { return $this->belongsTo(Tenant::class); }
}
