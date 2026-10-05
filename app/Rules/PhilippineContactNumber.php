<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilippineContactNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[+0-9\\s().-]+$/', $value)) {
            $fail('Enter a valid Philippine mobile or landline number.');

            return;
        }

        $number = preg_replace('/[\\s().-]/', '', $value);

        if (! preg_match('/^(?:0\\d{9,10}|\\+63\\d{9,10}|\\d{7,8})$/', $number)) {
            $fail('Enter a Philippine number such as 0917 123 4567 or +63 917 123 4567.');
        }
    }
}
