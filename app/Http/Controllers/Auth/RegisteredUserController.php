<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only([
            'name', 'email', 'contact_number', 'address', 'password',
        ]));
        // These protected fields are assigned by the server, never from public input.
        $user->role = UserRole::Resident;
        $user->is_active = true;
        $user->save();

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
