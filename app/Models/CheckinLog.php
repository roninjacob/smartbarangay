<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckinLog extends Model
{
    public const ARRIVAL = 'checked_in';

    protected $hidden = ['scanned_ticket_code'];

    protected static function booted(): void
    {
        $immutable = function (self $log) {
            if ($log->getRawOriginal('verification_result') === self::ARRIVAL) {
                throw new \LogicException('Recorded check-ins cannot be edited or deleted.');
            }
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    protected $table = 'checkin_logs';

    protected $fillable = [
        'reservation_id',
        'scanned_ticket_code',
        'verified_by',
        'verification_result',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
