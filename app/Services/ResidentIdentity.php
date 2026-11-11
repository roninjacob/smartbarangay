<?php

namespace App\Services;

use App\Models\ResidentProfile;
use App\Models\User;

class ResidentIdentity
{
    public const NAME_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix'];

    public const PROFILE_FIELDS = ['birthdate', 'house_unit_number', 'address_street', 'address_zone_purok'];

    public const LOCALITY = 'Barangay Calayo, Nasugbu, Batangas';

    public static function name(array $values): string
    {
        return implode(' ', array_filter(array_map(fn ($key) => preg_replace('/\s+/u', ' ', trim($values[$key] ?? '')), self::NAME_FIELDS), fn ($value) => $value !== ''));
    }

    public static function address(ResidentProfile $profile): string
    {
        return implode(', ', array_filter([$profile->house_unit_number, $profile->address_street,
            $profile->address_zone_purok, self::LOCALITY], fn ($value) => is_string($value) && trim($value) !== ''));
    }

    /** Fill only validated identity fields. Call inside the locked profile transaction. */
    public function fill(User $user, ResidentProfile $profile, array $values): void
    {
        $user->forceFill(array_replace(array_fill_keys(self::NAME_FIELDS, null), array_intersect_key($values, array_flip(self::NAME_FIELDS))));
        $user->name = self::name($user->only(self::NAME_FIELDS));
        $user->contact_number = $values['contact_number'];
        $profile->forceFill(array_intersect_key($values, array_flip(self::PROFILE_FIELDS)));
        $user->address = self::address($profile);
    }
}
