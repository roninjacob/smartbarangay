<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FilterReservationsRequest extends FormRequest
{
    protected $redirectRoute = 'admin.reservations.index';

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::enum(ReservationStatus::class)]];
    }
}
