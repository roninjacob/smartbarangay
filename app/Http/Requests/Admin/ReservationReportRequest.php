<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationReportRequest extends FormRequest
{
    protected $redirectRoute = 'admin.reports.reservations';

    public const MODES = ['month' => 'This Month', 'today' => 'Today', 'yesterday' => 'Yesterday',
        'week' => 'This Week', 'date' => 'Specific Date', 'custom' => 'Custom Date Range'];

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'required', Rule::in(array_keys(self::MODES))],
            'date' => [Rule::excludeIf($this->input('period', 'month') !== 'date'), 'required', 'date_format:Y-m-d'],
            'start' => [Rule::excludeIf($this->input('period', 'month') !== 'custom'), 'required', 'date_format:Y-m-d'],
            'end' => [Rule::excludeIf($this->input('period', 'month') !== 'custom'), 'required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
