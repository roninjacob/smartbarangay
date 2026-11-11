<?php

namespace App\Http\Requests\Admin;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewResidencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return [
            'residency_status' => ['required', Rule::in(['approved', 'needs_correction', 'rejected'])],
            'expected_residency_revision' => ['required', 'integer', 'min:0'],
            'resident_message' => [Rule::requiredIf($this->input('residency_status') !== ResidencyStatus::Approved->value), 'nullable', 'string', 'max:1000'],
        ];
    }
}
