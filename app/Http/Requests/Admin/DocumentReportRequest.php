<?php

namespace App\Http\Requests\Admin;

class DocumentReportRequest extends ReservationReportRequest
{
    protected $redirectRoute = 'admin.reports.documents';
}
