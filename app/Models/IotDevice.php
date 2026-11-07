<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotDevice extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return ['credential' => 'encrypted', 'credential_version' => 'integer', 'revoked_at' => 'immutable_datetime'];
    }
}
