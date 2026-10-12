<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrTicket extends Model
{
    protected $hidden = ['ticket_code', 'qr_payload', 'used_at', 'used_by'];

    public function encodedPayload(): string
    {
        return 'SBQ1:'.$this->qr_payload;
    }

    protected $fillable = [
        'reservation_id',
        'ticket_code',
        'qr_payload',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
