<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReservationStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReservationStatus::class)],
            'expected_status' => ['required', Rule::enum(ReservationStatus::class)],
            'notes' => [Rule::requiredIf($this->input('status') === ReservationStatus::Rejected->value), 'nullable', 'string', 'max:2000'],
        ];
    }
}
