<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReservationCancellationRequest extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            if ($request->getOriginal('status') !== 'pending'
                || $request->isDirty(['reservation_id', 'requested_by', 'reason', 'requested_at'])) {
                throw new LogicException('Cancellation history cannot be rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('Cancellation history cannot be deleted.'));
    }
}
