<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationAttachment extends Model
{
    protected $hidden = ['stored_path'];

    protected $fillable = [
        'reservation_id',
        'service_requirement_id',
        'original_filename',
        'stored_path',
        'mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function serviceRequirement(): BelongsTo
    {
        return $this->belongsTo(ServiceRequirement::class);
    }
}
