<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\CheckInReservations;
use App\Services\IotVerificationEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationCheckInController extends Controller
{
    public function store(Request $request, Reservation $reservation, CheckInReservations $checkins): RedirectResponse
    {
        // iot_event only lets the scanner display the recorded outcome; it grants nothing.
        $rules = ['confirm_checkin' => ['required', 'accepted'], 'iot_event' => ['sometimes', 'nullable', 'uuid']];
        foreach (['checked_in_at', 'checked_in_by', 'resident_id', 'reservation_id', 'status', 'qr_used', 'payment_status', 'source', 'verified_at', 'verified_by', 'verification_result'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $request->validate($rules);
        $log = $checkins->record($reservation, $request->user(), $request->session()->get(CheckInReservations::SESSION_KEY));
        if (is_string($request->input('iot_event'))) {
            app(IotVerificationEvents::class)->recorded($request->input('iot_event'), hash('sha256', $request->session()->getId()));
        }

        // One-time flag for the optional check-in sound on the next page (a refresh does not replay it).
        if ($log->wasRecentlyCreated) {
            $request->session()->flash('checkin_recorded', true);
        }

        return to_route('admin.reservations.show', $reservation)->with('status', $log->wasRecentlyCreated
            ? 'Checked In. Reservation status, payment and QR usage remain unchanged.' : 'Already checked in. The original arrival record is unchanged.');
    }
}
