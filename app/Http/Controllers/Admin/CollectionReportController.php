<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CollectionReportRequest;
use App\Services\CollectionReports;
use App\Services\ReportingExclusions;
use App\Services\ReservationReports;
use Illuminate\View\View;
use RuntimeException;

class CollectionReportController extends Controller
{
    public function __invoke(CollectionReportRequest $request, CollectionReports $collections, ReservationReports $periods): View
    {
        $filters = ['period' => 'month', 'view' => 'valid', ...$request->safe()->except('page')];
        $period = $periods->period($filters);
        $report = null;
        $reportError = null;
        try {
            if (! $request->session()->has('errors')) {
                $report = $collections->data($filters, $period);
            }
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== ReportingExclusions::UNAVAILABLE) {
                throw $exception;
            }
            $reportError = 'Collections reports are unavailable until the reporting configuration and reconciliation are verified. Contact the system administrator.';
        }

        return view('admin.reports.collections', ['roleLabel' => 'Admin', 'navigation' => config('navigation.admin'),
            'dashboardDate' => now('Asia/Manila'), 'pageTitle' => 'Collections Reports', 'pageHeading' => 'Collections Reports',
            'pageSection' => 'Reports & Analytics', 'modes' => CollectionReportRequest::MODES,
            'filters' => $filters, 'period' => $period, 'report' => $report, 'reportError' => $reportError]);
    }
}
