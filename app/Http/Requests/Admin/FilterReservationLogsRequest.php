<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Services\ReservationActivityTimeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterReservationLogsRequest extends FormRequest
{
    protected $redirectRoute = 'admin.reservation-logs.index';

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(array_keys(ReservationActivityTimeline::TYPES))]];
    }
}
