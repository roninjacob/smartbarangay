<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordSessionRevocation
{
    /** Broker-authorized guest reset: revoke every existing session, without signing in. */
    public function reset(User $user, string $password): void
    {
        $guard = Auth::guard('web');
        try {
            DB::transaction(function () use ($user, $password, $guard) {
                $locked = User::lockForUpdate()->findOrFail($user->id);
                $locked->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                // logoutOtherDevices needs an in-memory guard user. setUser does not create a login session.
                $guard->setUser($locked);
                Auth::logoutOtherDevices($password);
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                        ->where('user_id', $locked->id)->delete();
                }
                $user->setRawAttributes($locked->getAttributes(), true);
            });
        } finally {
            // A stale incoming remember cookie must not mint a fresh remembered login during a guest reset.
            Cookie::unqueue($guard->getRecallerName());
            $guard->forgetUser();
        }
    }
}
