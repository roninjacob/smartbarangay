<?php

namespace App\Rules;

use App\Services\ResidentIdentity;
use Illuminate\Validation\Validator;

class ResidentIdentityRules
{
    public static function rules(): array
    {
        $name = ['string', 'regex:/\A[\p{L}][\p{L}\p{M} .\x{2019}\x{0027}-]*\z/u', 'max:80'];

        return [
            'first_name' => ['required', ...$name], 'middle_name' => ['nullable', ...$name],
            'last_name' => ['required', ...$name],
            'suffix' => ['nullable', 'string', 'max:20', 'regex:/\A[\p{L}\p{N}][\p{L}\p{N} .\x{0027}-]*\z/u'],
            'birthdate' => ['required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'contact_number' => ['required', 'string', 'max:30', new PhilippineContactNumber],
            'house_unit_number' => ['nullable', 'string', 'max:80'],
            'address_street' => ['required', 'string', 'max:255'],
            'address_zone_purok' => ['nullable', 'string', 'max:120'],
        ];
    }

    public static function validateLength(Validator $validator): void
    {
        if ($validator->errors()->isEmpty() && mb_strlen(ResidentIdentity::name($validator->getData())) > 255) {
            $validator->errors()->add('first_name', 'The combined full name must not exceed 255 characters.');
        }
    }

    public static function normalize(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value) && in_array($key, [...ResidentIdentity::NAME_FIELDS, ...ResidentIdentity::PROFILE_FIELDS], true)) {
                $values[$key] = preg_replace('/\s+/u', ' ', trim($value));
                $values[$key] = $values[$key] === '' ? null : $values[$key];
            }
        }

        return $values;
    }
}
