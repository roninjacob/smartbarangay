<?php

namespace App\Http\Requests\Auth;

use App\Rules\ResidentIdentityRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ResidentIdentityRules::normalize($this->all()));
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        return [
            ...ResidentIdentityRules::rules(),
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'full name', 'contact_number' => 'contact number'];
    }

    public function after(): array
    {
        return [ResidentIdentityRules::validateLength(...)];
    }
}
