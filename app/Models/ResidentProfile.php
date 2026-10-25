<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentProfile extends Model
{
    public const TEXT_LENGTHS = ['civil_status' => 80, 'occupation' => 120, 'address_street' => 255,
        'address_zone_purok' => 120, 'emergency_contact_name' => 255, 'emergency_contact_number' => 30];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['birthdate' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
