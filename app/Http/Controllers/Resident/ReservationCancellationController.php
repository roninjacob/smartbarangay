<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Resident\CancelReservationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationCancellationController extends Controller
{
    public function __invoke(CancelReservationRequest $request, string $reservation): RedirectResponse
    {
        DB::transaction(function () use ($request, $reservation) {
            // Same reservation row lock as Admin processing; whichever action wins determines the next allowed action.
            $owned = $request->user()->reservations()->whereKey($reservation)->lockForUpdate()->firstOrFail();
            if (! $owned->status->canBeCancelledByResident()) {
                throw ValidationException::withMessages(['reason' => 'Only a Pending reservation can be cancelled. Review the current request status.']);
            }
            $owned->update(['status' => ReservationStatus::Cancelled]);
            $owned->statusHistories()->create(['from_status' => ReservationStatus::Pending,
                'to_status' => ReservationStatus::Cancelled, 'changed_by' => $request->user()->id,
                'notes' => $request->validated('reason'), 'changed_at' => now()]);
        });

        return to_route('resident.request-status.show', $reservation)->with('status', 'Reservation cancelled. Your request and cancellation history have been preserved.');
    }
}
