<?php

namespace App\Models;

use App\Enums\ResidencyStatus;
use App\Enums\UserRole;
use App\Services\ResidentIdentity;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    public const UNVERIFIED_RETENTION_DAYS = 7;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['residency_status' => 'pending', 'residency_revision' => 0];

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
            'residency_status' => ResidencyStatus::class,
            'residency_revision' => 'integer',
            'residency_reviewed_at' => 'immutable_datetime',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function residencyReviews(): HasMany
    {
        return $this->hasMany(ResidencyReview::class);
    }

    public function canSubmitReservations(): bool
    {
        return $this->role === UserRole::Resident && $this->is_active && $this->hasVerifiedEmail()
            && $this->residency_status === ResidencyStatus::Approved;
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ResidentProfile::class);
    }

    public function hasStructuredName(): bool
    {
        return filled($this->first_name) && filled($this->last_name);
    }

    public function getFullNameAttribute(): string
    {
        return $this->hasStructuredName()
            ? ResidentIdentity::name($this->only(ResidentIdentity::NAME_FIELDS))
            : (string) $this->name;
    }

    public function ownedProfilePicturePath(): ?string
    {
        $path = $this->profile_picture;

        return is_string($path) && preg_match('#^profile-pictures/'.$this->id.'/[a-zA-Z0-9]+\.(jpg|jpeg|png|webp)$#D', $path)
            ? $path : null;
    }

    public function homeRouteName(): string
    {
        return match ($this->role) {
            UserRole::Resident => 'resident.home',
            UserRole::Admin => 'admin.home',
        };
    }

    public function preferredTheme(): string
    {
        return $this->theme_preference === 'dark' ? 'dark' : 'light';
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
