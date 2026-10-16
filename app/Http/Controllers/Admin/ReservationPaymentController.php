<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationPayments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationPaymentController extends Controller
{
    public function store(Request $request, Reservation $reservation, ReservationPayments $payments): RedirectResponse
    {
        $request->validate(['confirm_payment' => ['required', 'accepted'], 'amount' => ['prohibited'],
            'fee_snapshot' => ['prohibited'], 'recorded_by' => ['prohibited'], 'paid_at' => ['prohibited'],
            'reservation_id' => ['prohibited'], 'payment_method' => ['prohibited']]);
        $payments->record($reservation, $request->user());

        return to_route('admin.reservations.show', $reservation)->with('status', 'On-site payment recorded. Reservation status is unchanged.');
    }
}
