<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class AdminUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'role', 'active', 'last_login_at'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['active' => 'boolean', 'last_login_at' => 'datetime', 'password' => 'hashed'];

    public function isSuper(): bool { return $this->role === 'super'; }
    public function canWrite(): bool { return in_array($this->role, ['super', 'sales']); }
}
