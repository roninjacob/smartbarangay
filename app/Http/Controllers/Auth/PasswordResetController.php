<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class PasswordResetController extends Controller
{
    public const SENT_MESSAGE = 'If an eligible account exists for that email address, a password reset link has been sent.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function send(PasswordResetLinkRequest $request): RedirectResponse
    {
        try {
            Password::sendResetLink($this->verifiedCredentials($request->validated()));
        } catch (TransportExceptionInterface $exception) {
            // Do not reveal that this address matched an account, even on mail failure.
            report($exception);
        }

        return redirect()->route('password.request')->with('status', self::SENT_MESSAGE);
    }

    public function reset(Request $request, string $token): View|Response
    {
        $email = $request->query('email', '');
        $email = is_string($email) ? strtolower(trim($email)) : '';
        $user = strlen($email) <= 255 ? User::where('email', $email)->first() : null;
        if (! $user || ! $user->hasVerifiedEmail() || ! Password::tokenExists($user, $token)) {
            return response()->view('auth.reset-invalid', [], 400);
        }

        return view('auth.reset-password', compact('token', 'email'));
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset($this->verifiedCredentials($request->validated()), function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return redirect()->route('password.request')->with('reset_error',
                'This password reset link is invalid or has expired.');
        }

        return redirect()->route('login')->with('status',
            'Your password has been reset successfully. You may now sign in.');
    }

    private function verifiedCredentials(array $credentials): array
    {
        // Apply eligibility inside the broker's user lookup for both issuing and redeeming links.
        $credentials[] = fn (Builder $query) => $query->whereNotNull('email_verified_at');

        return $credentials;
    }
}
