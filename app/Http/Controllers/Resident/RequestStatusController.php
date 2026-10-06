<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RequestStatusController extends Controller
{
    public function index(Request $request): View
    {
        return view('resident.request-status.index', [...$this->shellData('Request Status'),
            'reservations' => $request->user()->reservations()->with(['service', 'schedule'])
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(10)]);
    }

    public function show(Request $request, string $reservation): View
    {
        $owned = $request->user()->reservations()->whereKey($reservation)->firstOrFail();
        $owned->load(['service', 'schedule']);

        return view('resident.request-status.show', [...$this->shellData('Request Progress'), 'reservation' => $owned,
            // Processing notes and actor details are Admin-only. Do not even select them for Resident views.
            'histories' => $owned->statusHistories()->select(['id', 'reservation_id', 'from_status', 'to_status', 'changed_at'])
                ->orderBy('changed_at')->orderBy('id')->paginate(10),
            'steps' => array_values(array_filter(ReservationStatus::cases(), fn ($status) => $status !== ReservationStatus::Rejected))]);
    }

    private function shellData(string $heading): array
    {
        return ['roleLabel' => 'Resident', 'navigation' => config('navigation.resident'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => $heading, 'pageHeading' => $heading, 'pageSection' => 'Request Status'];
    }
}
