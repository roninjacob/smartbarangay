<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function occupiedReservations(): HasMany
    {
        return $this->reservations()->occupyingSlot();
    }
}
