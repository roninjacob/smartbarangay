<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckInReportRequest;
use App\Http\Requests\Admin\DocumentReportRequest;
use App\Http\Requests\Admin\ReservationReportRequest;
use App\Services\RecordedEventReports;
use App\Services\ReportingExclusions;
use App\Services\ReservationReports;
use Illuminate\View\View;
use RuntimeException;

class RecordedEventReportController extends Controller
{
    public function checkins(CheckInReportRequest $request): View
    {
        return $this->render($request, 'checkins', 'Recorded Check-ins');
    }

    public function documents(DocumentReportRequest $request): View
    {
        return $this->render($request, 'documents', 'Finalized PDFs');
    }

    private function render(ReservationReportRequest $request, string $kind, string $title): View
    {
        $filters = ['period' => 'month', ...$request->safe()->except('page')];
        $period = app(ReservationReports::class)->period($filters);
        $report = null;
        $reportError = null;
        try {
            if (! $request->session()->has('errors')) {
                $report = app(RecordedEventReports::class)->data($kind, $filters, $period);
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== ReportingExclusions::UNAVAILABLE) {
                throw $exception;
            }
            $reportError = 'This report is unavailable until the reporting configuration and reconciliation are verified. Contact the system administrator.';
        }

        return view('admin.reports.'.$kind, ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => $title, 'pageHeading' => $title,
            'pageSection' => 'Reports & Analytics', 'modes' => ReservationReportRequest::MODES,
            'filters' => $filters, 'period' => $period, 'report' => $report, 'reportError' => $reportError,
            'kind' => $kind, 'title' => $title]);
    }
}
