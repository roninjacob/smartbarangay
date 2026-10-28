<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReservationAttendanceReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Attendance review history is immutable.'));
        static::deleting(fn () => throw new LogicException('Attendance review history cannot be deleted.'));
    }
}
