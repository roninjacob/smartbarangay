<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotDeviceState extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'device_id';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['last_payload_hash'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime', 'last_scan_at' => 'immutable_datetime', 'frame_at' => 'immutable_datetime'];
    }
}
