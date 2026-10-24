<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CheckInReportRequest;
use App\Http\Requests\Admin\CollectionReportRequest;
use App\Http\Requests\Admin\DocumentReportRequest;
use App\Http\Requests\Admin\ReservationReportRequest;
use App\Services\ReportCsvExports;
use App\Services\ReportExportException;
use App\Services\ReportingExclusions;
use App\Services\ReservationReports;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ReportExportController extends Controller
{
    public function reservations(ReservationReportRequest $request): Response
    {
        return $this->download($request, 'reservations');
    }

    public function collections(CollectionReportRequest $request): Response
    {
        return $this->download($request, 'collections');
    }

    public function checkins(CheckInReportRequest $request): Response
    {
        return $this->download($request, 'checkins');
    }

    public function documents(DocumentReportRequest $request): Response
    {
        return $this->download($request, 'documents');
    }

    private function download(ReservationReportRequest $request, string $kind): Response
    {
        // HEAD remains read-only metadata access and never materializes a CSV.
        if ($request->isMethod('HEAD')) {
            return response('', 200, ['Cache-Control' => 'private, no-store']);
        }
        $filters = ['period' => 'month', ...$request->safe()->except('page')];
        try {
            return app(ReportCsvExports::class)->download($kind, $filters, app(ReservationReports::class)->period($filters));
        } catch (Throwable $exception) {
            $known = $exception instanceof ReportExportException || $exception->getMessage() === ReportingExclusions::UNAVAILABLE;
            if (! $known) {
                report($exception);
            }
            $message = $known ? $exception->getMessage() : 'CSV preparation failed. No download was created. Contact the system administrator.';

            return redirect()->route('admin.reports.'.$kind, $filters)->withErrors(['export' => $message]);
        }
    }
}
