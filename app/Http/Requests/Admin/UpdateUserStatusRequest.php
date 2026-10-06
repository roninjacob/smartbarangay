<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Admin;
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean'], 'expected_is_active' => ['required', 'boolean'],
            'role' => ['prohibited'], 'email_verified_at' => ['prohibited'], 'password' => ['prohibited'],
            'remember_token' => ['prohibited'], 'name' => ['prohibited'], 'email' => ['prohibited'],
            'contact_number' => ['prohibited'], 'address' => ['prohibited']];
    }
}
