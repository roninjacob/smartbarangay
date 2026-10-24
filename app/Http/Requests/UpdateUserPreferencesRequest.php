<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUserPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role, [UserRole::Admin, UserRole::Resident], true)
            && $user->is_active && $user->hasVerifiedEmail();
    }

    public function rules(): array
    {
        return ['theme_preference' => ['required', 'string', Rule::in(['light', 'dark'])]];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (array_diff(array_keys($this->all()), ['theme_preference', '_token', '_method']) !== []) {
                $validator->errors()->add('preferences', 'Only your appearance preference can be updated here.');
            }
        }];
    }
}
