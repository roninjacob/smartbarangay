<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class AccountEligibility
{
    // Acquire the actor before business records, so revocation and writes serialize.
    public function lock(User|int $actor, UserRole $role): User
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Account eligibility requires an active transaction.');
        }
        $user = User::whereKey($actor instanceof User ? $actor->id : $actor)->lockForUpdate()->first();
        abort_unless($user && $user->role === $role && $user->is_active && $user->hasVerifiedEmail(), 403,
            'Your account is no longer eligible to perform this action.');

        return $user;
    }
}
