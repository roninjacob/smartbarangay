<?php

namespace App\Http\Requests\Resident;

use App\Enums\UserRole;
use App\Rules\PhilippineContactNumber;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Resident;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', new PhilippineContactNumber],
            'address' => ['nullable', 'string', 'max:1000'],
            'user_id' => ['prohibited'], 'id' => ['prohibited'], 'role' => ['prohibited'],
            'is_active' => ['prohibited'], 'email_verified_at' => ['prohibited'],
            'email' => ['prohibited'], 'password' => ['prohibited'], 'remember_token' => ['prohibited'],
            'profile_picture' => ['prohibited'], 'permissions' => ['prohibited'],
        ];
    }
}
