<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenceDevice extends Model
{
    protected $fillable = ['licence_id','device_uid','hostname','status','activated_at','deactivated_at'];
    protected $casts = ['activated_at'=>'datetime','deactivated_at'=>'datetime'];
    public function licence() { return $this->belongsTo(Licence::class); }
}
