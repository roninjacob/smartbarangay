<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotDeviceCommand extends Model
{
    public const SET_ENABLED = 'set_enabled';

    public const RESTART = 'restart';

    // Lifecycle: pending -> delivered -> (restarting) -> applied; or failed, expired, superseded.
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const RESTARTING = 'restarting';

    public const APPLIED = 'applied';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const SUPERSEDED = 'superseded';

    public const OPEN = [self::PENDING, self::DELIVERED, self::RESTARTING];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    // Millisecond timestamps, so command acknowledgment latency is measurable.
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'revision' => 'integer', 'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
