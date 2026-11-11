<?php

namespace App\Models;

use App\Enums\ResidencyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ResidencyReview extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['from_status' => ResidencyStatus::class, 'to_status' => ResidencyStatus::class,
            'reviewed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Residency decisions cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Residency decisions cannot be deleted.'));
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
