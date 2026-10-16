<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\ReservationPayment;
use App\Models\User;
use App\Support\ServiceFee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationPayments
{
    public function status(Reservation $reservation): PaymentStatus
    {
        $reservation->loadMissing('payment');
        $fee = $reservation->fee_snapshot;
        if ($reservation->payment) {
            return $reservation->payment->matchesFee($fee) ? PaymentStatus::Paid : PaymentStatus::IntegrityIssue;
        }
        if ($fee === null) {
            return PaymentStatus::FeeNotRecorded;
        }
        if (! preg_match(ServiceFee::PATTERN, $fee)) {
            return PaymentStatus::IntegrityIssue;
        }

        return $fee === '0.00' ? PaymentStatus::NoPaymentRequired : PaymentStatus::Unpaid;
    }

    public function record(Reservation $reservation, User $admin): ReservationPayment
    {
        abort_unless($admin->role === UserRole::Admin && $admin->is_active && $admin->hasVerifiedEmail(), 403);
        try {
            return DB::transaction(function () use ($reservation, $admin) {
                $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
                $status = $this->status($locked);
                if ($locked->payment) {
                    throw ValidationException::withMessages(['payment' => 'A payment already exists for this reservation. It cannot be overwritten. Review the recorded payment.']);
                }
                if ($locked->status !== ReservationStatus::ReadyForPickup || $status !== PaymentStatus::Unpaid) {
                    throw ValidationException::withMessages(['payment' => 'Payment can only be recorded for a Ready for Pickup reservation with a known positive fee.']);
                }
                $payment = new ReservationPayment;
                $payment->forceFill(['reservation_id' => $locked->id, 'amount' => $locked->fee_snapshot,
                    'paid_at' => now('UTC'), 'recorded_by' => $admin->id])->save();

                return $payment;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['payment' => 'A payment already exists for this reservation. Reload the reservation details.']);
        }
    }
}
