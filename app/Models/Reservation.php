<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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
            'fee_snapshot' => 'decimal:2',
            'resident_snapshot' => 'array',
            'document_template_snapshot' => 'array',
            'appointment_date' => 'date',
            'requirements_snapshotted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Reservation $reservation) {
            if ($reservation->isDirty(['appointment_date', 'appointment_start_time', 'appointment_end_time', 'requirements_snapshotted_at'])) {
                throw new LogicException('Reservation history snapshots cannot be changed.');
            }
        });
    }

    public function hasAppointmentSnapshot(): bool
    {
        return $this->appointment_date !== null && $this->appointment_start_time !== null && $this->appointment_end_time !== null;
    }

    public function getAppointmentAttribute(): Schedule
    {
        return $this->hasAppointmentSnapshot()
            ? new Schedule(['date' => $this->appointment_date, 'start_time' => $this->appointment_start_time, 'end_time' => $this->appointment_end_time])
            : $this->schedule;
    }

    public function requirementSnapshots(): HasMany
    {
        return $this->hasMany(ReservationRequirement::class)->orderBy('id');
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

    public function documentAnswers(): HasMany
    {
        return $this->hasMany(ReservationDocumentAnswer::class);
    }

    public function document(): HasOne
    {
        return $this->hasOne(ReservationDocument::class);
    }

    public function qrTicket(): HasOne
    {
        return $this->hasOne(QrTicket::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(ReservationPayment::class);
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(ReservationCancellationRequest::class)->orderByDesc('id');
    }

    public function attendanceReview(): HasOne
    {
        return $this->hasOne(ReservationAttendanceReview::class);
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
