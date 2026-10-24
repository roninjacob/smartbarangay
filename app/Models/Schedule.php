<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Schedule extends Model
{
    protected $fillable = [
        'date',
        'start_time',
        'end_time',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        $now = now('Asia/Manila');

        return $query->where(function (Builder $query) use ($now) {
            $query->whereDate('date', '>', $now->toDateString())
                ->orWhere(fn (Builder $today) => $today->whereDate('date', $now->toDateString())->where('start_time', '>', $now->format('H:i:s')));
        });
    }

    public function startsInFuture(): bool
    {
        return Carbon::parse($this->date->toDateString().' '.$this->start_time, 'Asia/Manila')->gt(now('Asia/Manila'));
    }

    public function occupiedReservations(): HasMany
    {
        return $this->reservations()->occupyingSlot();
    }

    public function remainingSlots(): int
    {
        return max(0, $this->capacity - ($this->occupied_reservations_count ?? $this->occupiedReservations()->count()));
    }

    // Call inside a transaction after locking this schedule. A locking read avoids stale MySQL snapshots.
    public function lockedOccupancy(): int
    {
        return $this->occupiedReservations()->lockForUpdate()->get(['id'])->count();
    }
}
