<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\ReservationFollowups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationFollowupController extends Controller
{
    public function requestCancellation(Request $request, Reservation $reservation, ReservationFollowups $followups): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirm_request' => ['required', 'accepted']]);
        $followups->requestCancellation($request->user(), $reservation->id, $data['reason']);

        return to_route('resident.reservations.show', $reservation)->with('status', 'Cancellation requested. Wait for the office decision; your reservation is not cancelled yet.');
    }

    public function reviewCancellation(Request $request, Reservation $reservation, string $cancellation, ReservationFollowups $followups): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,declined'],
            'decision_message' => ['required', 'string', 'max:1000'], 'expected_status' => ['required', 'string'], 'confirm_review' => ['required', 'accepted']]);
        $followups->reviewCancellation($request->user(), $reservation->id, (int) $cancellation,
            $data['decision'], $data['decision_message'], $data['expected_status']);

        return to_route('admin.reservations.show', $reservation)->with('status', 'Cancellation decision recorded. Financial records, files and QR usage were not overwritten.');
    }

    public function missed(Request $request, Reservation $reservation, ReservationFollowups $followups): RedirectResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:1000'], 'confirm_missed' => ['required', 'accepted']]);
        $followups->recordMissed($request->user(), $reservation->id, $data['notes']);

        return to_route('admin.reservations.show', $reservation)->with('status', 'Missed appointment confirmed. Processing status, payment, QR and document remain unchanged.');
    }
}
