<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReservationReportRequest;
use App\Services\ReportingExclusions;
use App\Services\ReservationReports;
use Illuminate\View\View;
use RuntimeException;

class ReservationReportController extends Controller
{
    public function __invoke(ReservationReportRequest $request, ReservationReports $reports): View
    {
        $filters = ['period' => 'month', ...$request->safe()->except('page')];
        $period = $reports->period($filters);
        $error = null;
        $data = null;
        try {
            if (! $request->session()->has('errors')) {
                $data = $reports->data($filters, $period);
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== ReportingExclusions::UNAVAILABLE) {
                throw $exception;
            }
            $error = ReportingExclusions::UNAVAILABLE;
        }

        return view('admin.reports.reservations', [
            'roleLabel' => 'Admin', 'navigation' => config('navigation.admin'), 'dashboardDate' => now('Asia/Manila'),
            'pageTitle' => 'Reservation Reports', 'pageHeading' => 'Reservation Reports', 'pageSection' => 'Reports & Analytics',
            'filters' => $filters, 'period' => $period, 'modes' => ReservationReportRequest::MODES,
            'statuses' => ReservationStatus::cases(), 'report' => $data, 'reportError' => $error,
        ]);
    }
}
