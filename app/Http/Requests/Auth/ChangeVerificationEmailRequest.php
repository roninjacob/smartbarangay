<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeVerificationEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Resident
            && $this->user()->requiresEmailVerification();
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id), Rule::notIn([$this->user()->email])],
            'current_password' => ['required', 'string', 'max:72', 'current_password:web'],
        ];
    }
}
