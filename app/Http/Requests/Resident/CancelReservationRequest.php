<?php

namespace App\Http\Requests\Resident;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class CancelReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Resident;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
