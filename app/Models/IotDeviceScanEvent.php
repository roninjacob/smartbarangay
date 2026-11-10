<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotDeviceScanEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted'];
    }
}
