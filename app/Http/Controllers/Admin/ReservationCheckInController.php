<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\CheckInReservations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationCheckInController extends Controller
{
    public function store(Request $request, Reservation $reservation, CheckInReservations $checkins): RedirectResponse
    {
        $rules = ['confirm_checkin' => ['required', 'accepted']];
        foreach (['checked_in_at', 'checked_in_by', 'resident_id', 'reservation_id', 'status', 'qr_used', 'payment_status', 'source', 'verified_at', 'verified_by', 'verification_result'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $request->validate($rules);
        $log = $checkins->record($reservation, $request->user(), $request->session()->get(CheckInReservations::SESSION_KEY));

        return to_route('admin.reservations.show', $reservation)->with('status', $log->wasRecentlyCreated
            ? 'Checked In. Reservation status, payment and QR usage remain unchanged.' : 'Already checked in. The original arrival record is unchanged.');
    }
}
