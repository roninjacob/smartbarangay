<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotScannerPairing extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'device_id';

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['session_id', 'session_hash', 'binding_hash', 'auth_hash', 'challenge_hash'];

    protected function casts(): array
    {
        return ['session_id' => 'encrypted', 'challenge_expires_at' => 'immutable_datetime',
            'paired_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'last_browser_seen_at' => 'immutable_datetime', 'credential_version' => 'integer',
            'last_scan_session' => 'integer', 'last_sequence' => 'integer', 'last_captured_at_ms' => 'integer'];
    }
}
