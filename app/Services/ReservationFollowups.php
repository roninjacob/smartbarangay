<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\CheckinLog;
use App\Models\Reservation;
use App\Models\ReservationAttendanceReview;
use App\Models\ReservationCancellationRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationFollowups
{
    public function filter(Builder $query, ?string $filter): void
    {
        if ($filter === 'cancellation') {
            $query->whereHas('cancellationRequests', fn ($q) => $q->where('status', 'pending'));
        } elseif ($filter === 'missed') {
            $query->whereHas('attendanceReview');
        } elseif ($filter === 'past_appointment') {
            $now = now('Asia/Manila');
            $query->where('status', ReservationStatus::ReadyForPickup)
                ->whereNotNull('appointment_date')->whereNotNull('appointment_start_time')->whereNotNull('appointment_end_time')
                ->whereDoesntHave('checkinLogs', fn ($q) => $q->where('verification_result', CheckinLog::ARRIVAL))
                ->where(function ($q) use ($now) {
                    $q->whereDate('appointment_date', '<', $now->toDateString())
                        ->orWhere(fn ($q) => $q->whereDate('appointment_date', $now->toDateString())->where('appointment_end_time', '<', $now->format('H:i:s')));
                });
        }
    }

    public function cancellationBlock(Reservation $reservation): ?string
    {
        if (! in_array($reservation->status, [ReservationStatus::UnderReview, ReservationStatus::Approved, ReservationStatus::ReadyForPickup], true)) {
            return 'Cancellation review is available only for requests currently being processed. Pending requests use Cancel Reservation.';
        }
        if ($reservation->payment()->exists()) {
            return 'A payment record exists. Contact the office for a separate financial review; cancellation cannot refund or overwrite payments.';
        }
        if ($reservation->document()->exists()) {
            return 'A finalized document exists. Contact the office for a separate document correction review.';
        }
        if ($reservation->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->exists()) {
            return 'Arrival is already recorded. Contact the office for a separate transaction review.';
        }

        return null;
    }

    public function requestCancellation(User $resident, int $reservationId, string $reason): ReservationCancellationRequest
    {
        return DB::transaction(function () use ($resident, $reservationId, $reason) {
            app(AccountEligibility::class)->lock($resident, UserRole::Resident);
            $reservation = Reservation::where('user_id', $resident->id)->lockForUpdate()->findOrFail($reservationId);
            if ($reasonMessage = $this->cancellationBlock($reservation)) {
                $this->reject($reasonMessage);
            }
            if ($reservation->cancellationRequests()->where('status', 'pending')->exists()) {
                $this->reject('A cancellation request is already awaiting office review.');
            }
            $request = $reservation->cancellationRequests()->create([
                'open_reservation_id' => $reservation->id, 'requested_by' => $resident->id,
                'reason' => $reason, 'status' => 'pending', 'requested_at' => now('UTC'),
            ]);
            app(ReservationNotifications::class)->cancellationReview($reservation, $request);

            return $request;
        });
    }

    public function reviewCancellation(User $admin, int $reservationId, int $requestId, string $decision, string $message, string $expectedStatus): void
    {
        DB::transaction(function () use ($admin, $reservationId, $requestId, $decision, $message, $expectedStatus) {
            app(AccountEligibility::class)->lock($admin, UserRole::Admin);
            $reservation = Reservation::lockForUpdate()->findOrFail($reservationId);
            $request = $reservation->cancellationRequests()->lockForUpdate()->findOrFail($requestId);
            if ($request->status !== 'pending') {
                $this->reject('This cancellation request has already been reviewed. Reload the reservation.');
            }
            if (! in_array($decision, ['approved', 'declined'], true)) {
                $this->reject('Choose a valid cancellation decision.');
            }
            if ($reservation->status->value !== $expectedStatus) {
                $this->reject('The reservation status changed. Review the latest details before deciding.');
            }
            if ($decision === 'approved') {
                if ($reason = $this->cancellationBlock($reservation)) {
                    $this->reject($reason);
                }
                $previous = $reservation->status;
                $reservation->update(['status' => ReservationStatus::Cancelled]);
                $history = $reservation->statusHistories()->create([
                    'from_status' => $previous, 'to_status' => ReservationStatus::Cancelled,
                    'changed_by' => $admin->id, 'notes' => $message, 'resident_message' => $message, 'changed_at' => now('UTC'),
                ]);
                app(ReservationNotifications::class)->record('cancelled', $reservation, $history);
            }
            $request->update(['status' => $decision, 'open_reservation_id' => null,
                'reviewed_by' => $admin->id, 'decision_message' => $message, 'reviewed_at' => now('UTC')]);
            if ($decision === 'declined') {
                app(ReservationNotifications::class)->cancellationReview($reservation, $request);
            }
        });
    }

    public function appointmentEnd(Reservation $reservation): ?CarbonImmutable
    {
        // Legacy mutable master dates cannot establish historical missed-appointment evidence.
        return $reservation->hasAppointmentSnapshot()
            ? CarbonImmutable::parse($reservation->appointment_date->format('Y-m-d').' '.$reservation->appointment_end_time, 'Asia/Manila') : null;
    }

    public function attendanceBlock(Reservation $reservation): ?string
    {
        $end = $this->appointmentEnd($reservation);
        if (! $end || $end->greaterThanOrEqualTo(now('Asia/Manila'))) {
            return 'A preserved appointment must have ended before a missed appointment can be confirmed.';
        }
        if ($reservation->status !== ReservationStatus::ReadyForPickup) {
            return 'Only a Ready for Pickup reservation can be reviewed for missed pickup attendance.';
        }
        $readyAt = $reservation->statusHistories()->where('to_status', ReservationStatus::ReadyForPickup)->min('changed_at');
        if (! $readyAt || CarbonImmutable::parse($readyAt, 'UTC')->greaterThan($end->utc())) {
            return 'The document was not recorded as ready by the appointment end. Do not attribute the missed visit to the Resident.';
        }
        if ($reservation->checkinLogs()->where('verification_result', CheckinLog::ARRIVAL)->exists()) {
            return 'An arrival is already recorded.';
        }
        if ($reservation->attendanceReview()->exists()) {
            return 'A missed appointment review is already recorded.';
        }

        return null;
    }

    public function recordMissed(User $admin, int $reservationId, string $notes): ReservationAttendanceReview
    {
        return DB::transaction(function () use ($admin, $reservationId, $notes) {
            app(AccountEligibility::class)->lock($admin, UserRole::Admin);
            $reservation = Reservation::lockForUpdate()->findOrFail($reservationId);
            if ($reason = $this->attendanceBlock($reservation)) {
                $this->reject($reason);
            }

            return $reservation->attendanceReview()->create(['recorded_by' => $admin->id, 'notes' => $notes, 'recorded_at' => now('UTC')]);
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['followup' => $message]);
    }
}
