<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\ResidentProfile;
use App\Rules\PhilippineContactNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateResidentDemographicsRequest extends FormRequest
{
    protected $redirectRoute = 'resident.profile.edit';

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Resident;
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['birthdate', ...array_keys(ResidentProfile::TEXT_LENGTHS)] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $values[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($values);
    }

    public function rules(): array
    {
        $structured = $this->user()->fresh()->hasStructuredName();
        $rules = ['birthdate' => ['sometimes', $structured ? 'required' : 'nullable', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()]];
        foreach (ResidentProfile::TEXT_LENGTHS as $field => $length) {
            $address = in_array($field, ['address_street', 'address_zone_purok'], true);
            $rules[$field] = [$address ? 'sometimes' : 'present',
                $structured && $field === 'address_street' ? 'required' : 'nullable', 'string', 'max:'.$length];
        }
        $rules['emergency_contact_number'][] = new PhilippineContactNumber;

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $allowed = ['birthdate', ...array_keys(ResidentProfile::TEXT_LENGTHS), '_token', '_method'];
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('profile', 'Only your demographic profile fields can be updated here.');
            }
        }];
    }
}
