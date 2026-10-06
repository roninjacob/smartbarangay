<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReservationViewController extends Controller
{
    public function index(Request $request): View
    {
        return view('resident.reservations.index', [
            ...$this->shellData('My Reservations'),
            'reservations' => $request->user()->reservations()->with(['service', 'schedule'])
                ->latest()->orderByDesc('id')->paginate(10),
        ]);
    }

    public function show(Request $request, string $reservation): View
    {
        // Scope the lookup before loading related information. Foreign and missing IDs both return 404.
        $ownedReservation = $request->user()->reservations()->whereKey($reservation)->firstOrFail();
        $ownedReservation->load(['service.serviceRequirements', 'schedule']);

        return view('resident.reservations.show', [
            ...$this->shellData('Reservation Details'), 'reservation' => $ownedReservation,
        ]);
    }

    private function shellData(string $heading): array
    {
        return [
            'roleLabel' => 'Resident', 'navigation' => config('navigation.resident'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => $heading,
            'pageHeading' => $heading, 'pageSection' => 'My Reservations',
        ];
    }
}
