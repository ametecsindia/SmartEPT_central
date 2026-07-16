<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class TenantUser extends Authenticatable
{
    protected $fillable = ['tenant_id', 'name', 'email', 'phone', 'password', 'role',
        'active', 'email_verified_at', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['active' => 'boolean', 'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime', 'password' => 'hashed'];

    public function tenant() { return $this->belongsTo(Tenant::class); }
}
