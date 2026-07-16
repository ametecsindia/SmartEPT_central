<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['admin_user_id','action','subject_type','subject_id','meta'];
    protected $casts = ['meta'=>'array'];

    public static function write(string $action, $subject = null, array $meta = []): void
    {
        static::create([
            'admin_user_id' => auth('admin')->id(),
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->id,
            'meta' => $meta ?: null,
        ]);
    }
}
