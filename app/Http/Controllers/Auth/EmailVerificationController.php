<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangeVerificationEmailRequest;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return ! $request->user()->requiresEmailVerification()
            ? redirect()->route($request->user()->homeRouteName())
            : view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && hash_equals(sha1($user->getEmailForVerification()), (string) $request->route('hash')), 403);
            $request->setUserResolver(fn () => $user);
            $request->fulfill();
            Auth::setUser($user);
        });

        return redirect()->route($request->user()->homeRouteName())
            ->with('status', 'Email verified successfully.');
    }

    public function resend(Request $request): RedirectResponse
    {
        if (! $request->user()->requiresEmailVerification()) {
            return redirect()->route($request->user()->homeRouteName());
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $exception) {
            report($exception);
            return redirect()->route('verification.notice')->with('warning',
                'We could not send the verification email. Please try again shortly or contact the Barangay Calayo office.');
        }

        return redirect()->route('verification.notice')->with('status',
            'A new verification link has been sent to your email address.');
    }

    public function editEmail(Request $request): View|RedirectResponse
    {
        return $request->user()->role === UserRole::Resident && $request->user()->requiresEmailVerification()
            ? view('auth.change-verification-email')
            : redirect()->route($request->user()->entryRouteName());
    }

    public function updateEmail(ChangeVerificationEmailRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $user->role === UserRole::Resident && $user->requiresEmailVerification(), 403);
            abort_unless(Hash::check($request->validated('current_password'), $user->password), 403);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->email = $request->validated('email');
            $user->email_verified_at = null;
            $user->save();
            return $user;
        });
        $request->session()->forget('url.intended');
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        try {
            $user->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $exception) {
            report($exception);
            return redirect()->route('verification.notice')->with('warning',
                'Your email address was updated, but we could not send the verification email. Please try resending it shortly.');
        }
        return redirect()->route('verification.notice')->with('status',
            'Your email address has been updated. A new verification link has been sent.');
    }
}
