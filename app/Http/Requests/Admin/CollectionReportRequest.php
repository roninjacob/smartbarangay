<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class CollectionReportRequest extends ReservationReportRequest
{
    protected $redirectRoute = 'admin.reports.collections';

    public function rules(): array
    {
        return [...parent::rules(), 'view' => ['sometimes', 'required', Rule::in(['valid', 'integrity'])]];
    }
}
