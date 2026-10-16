<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FilterReservationLogsRequest;
use App\Services\ReservationActivityTimeline;
use Illuminate\View\View;

class ReservationLogController extends Controller
{
    public function index(FilterReservationLogsRequest $request, ReservationActivityTimeline $timeline): View
    {
        $filters = $request->validated();

        return view('admin.reservation-logs.index', [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Reservation Logs', 'pageHeading' => 'Reservation Logs', 'pageSection' => 'Barangay Operations',
            'activities' => $timeline->paginate($filters), 'filters' => $filters, 'types' => ReservationActivityTimeline::TYPES,
        ]);
    }
}
