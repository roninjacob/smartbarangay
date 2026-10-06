<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    public const UNVERIFIED_RETENTION_DAYS = 7;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'contact_number',
        'address',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function homeRouteName(): string
    {
        return match ($this->role) {
            UserRole::Resident => 'resident.home',
            UserRole::Admin => 'admin.home',
        };
    }

    public function entryRouteName(): string
    {
        return $this->requiresEmailVerification() ? 'verification.notice' : $this->homeRouteName();
    }

    public function requiresEmailVerification(): bool
    {
        return ! $this->hasVerifiedEmail();
    }

    public function scopeAbandonedResidents(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query->where('role', UserRole::Resident->value)
            ->whereNull('email_verified_at')->where('created_at', '<=', $cutoff);
    }

    public function checkinLogs(): HasMany
    {
        return $this->hasMany(CheckinLog::class, 'verified_by');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class, 'changed_by');
    }
}
