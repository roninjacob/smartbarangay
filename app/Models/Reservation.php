<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    protected $fillable = [
        'user_id',
        'service_id',
        'schedule_id',
        'purpose',
        'status',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReservationAttachment::class);
    }

    public function qrTicket(): HasOne
    {
        return $this->hasOne(QrTicket::class);
    }

    public function checkinLogs(): HasMany
    {
        return $this->hasMany(CheckinLog::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class);
    }

    public function scopeOccupyingSlot(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(fn ($status) => $status->value, ReservationStatus::occupyingStatuses()));
    }
}
