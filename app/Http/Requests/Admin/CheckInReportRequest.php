<?php

namespace App\Http\Requests\Admin;

class CheckInReportRequest extends ReservationReportRequest
{
    protected $redirectRoute = 'admin.reports.checkins';
}
