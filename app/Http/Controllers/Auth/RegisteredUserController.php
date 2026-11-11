<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\ResidentIdentity;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = new User($request->safe()->only(['email', 'password']));
            $profile = $user->profile()->make();
            app(ResidentIdentity::class)->fill($user, $profile, $request->validated());
            $user->role = UserRole::Resident;
            $user->is_active = true;
            $user->save();
            $profile->user_id = $user->id;
            $profile->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface $exception) {
            report($exception);

            return redirect()->route('verification.notice')->with('warning',
                'Your account was created, but we could not send the verification email. Please try resending it shortly.');
        }

        return redirect()->route('verification.notice')->with('status', 'Your account was created. A verification link has been sent to your email address.');
    }
}
