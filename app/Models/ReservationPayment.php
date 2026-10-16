<?php

namespace App\Models;

use App\Support\ServiceFee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReservationPayment extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Recorded payments cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Recorded payments cannot be deleted.'));
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function matchesFee(?string $fee): bool
    {
        return $fee !== null && $fee !== '0.00' && preg_match(ServiceFee::PATTERN, $fee)
            && $this->amount === $fee && $this->paid_at !== null;
    }

    public function scopeConsistent(Builder $query): Builder
    {
        return $query->whereHas('reservation', fn (Builder $reservation) => $reservation
            ->where('fee_snapshot', '>', 0)->whereColumn('reservations.fee_snapshot', 'reservation_payments.amount'));
    }
}
