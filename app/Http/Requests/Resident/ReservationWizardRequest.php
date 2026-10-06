<?php

namespace App\Http\Requests\Resident;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReservationWizardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Resident;
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'resident.reservations.service' => ['service_id' => ['required', 'integer', Rule::exists('services', 'id')->where('is_active', true)]],
            'resident.reservations.schedule.select' => ['schedule_id' => ['required', 'integer', Rule::exists('schedules', 'id')->where('is_active', true)]],
            'resident.reservations.store' => ['confirmation_token' => ['required', 'uuid']],
            default => [],
        };
    }
}
