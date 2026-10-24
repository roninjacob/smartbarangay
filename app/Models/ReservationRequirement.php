<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ReservationRequirement extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reservation requirement snapshots cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Reservation requirement snapshots cannot be deleted.'));
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReservationAttachment::class);
    }
}
