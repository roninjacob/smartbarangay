<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Rules\PhilippineContactNumber;
use App\Rules\ResidentIdentityRules;
use App\Services\ResidentIdentity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    private ?bool $formal = null;

    public function authorize(): bool
    {
        return $this->user()?->role === ($this->routeIs('admin.profile.*') ? UserRole::Admin : UserRole::Resident);
    }

    public function rules(): array
    {
        return [
            ...($this->formalIdentity() ? ResidentIdentityRules::rules() : [
                'name' => ['required', 'string', 'max:255'],
                'contact_number' => ['nullable', 'string', 'max:30', new PhilippineContactNumber],
                'address' => ['nullable', 'string', 'max:1000'],
            ]),
            'user_id' => ['prohibited'], 'id' => ['prohibited'], 'role' => ['prohibited'],
            'is_active' => ['prohibited'], 'email_verified_at' => ['prohibited'],
            'email' => ['prohibited'], 'password' => ['prohibited'], 'remember_token' => ['prohibited'],
            'profile_picture' => ['prohibited'], 'permissions' => ['prohibited'],
            'residency_status' => ['prohibited'], 'residency_revision' => ['prohibited'],
            'residency_reviewed_by' => ['prohibited'], 'residency_reviewed_at' => ['prohibited'],
        ];
    }

    public function formalIdentity(): bool
    {
        return $this->formal ??= $this->user()?->role === UserRole::Resident && ($this->user()->fresh()->hasStructuredName()
            || $this->hasAny([...ResidentIdentity::NAME_FIELDS, ...ResidentIdentity::PROFILE_FIELDS]));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(ResidentIdentityRules::normalize($this->all()));
    }

    public function after(): array
    {
        return $this->formalIdentity() ? [ResidentIdentityRules::validateLength(...)] : [];
    }
}
