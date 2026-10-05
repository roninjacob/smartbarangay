<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('verification-email', fn (Request $request) =>
            Limit::perMinute(1)->by('verification:'.$request->user()->id));
        RateLimiter::for('password-email', fn (Request $request) => [
            Limit::perMinute(5)->by('password-ip:'.$request->ip()),
            Limit::perMinute(1)->by('password-address:'.hash('sha256',
                strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')).'|'.$request->ip())),
        ]);

        VerifyEmail::toMailUsing(fn ($user, string $url) => (new MailMessage)
            ->subject('Verify your SmartBarangay email address')
            ->greeting('Hello '.$user->name.',')
            ->line('Welcome to SmartBarangay for Barangay Calayo, Nasugbu, Batangas.')
            ->line('Verify your email address to access your SmartBarangay account.')
            ->action('Verify Email Address', $url)
            ->line('This secure link expires in 60 minutes. If you did not create this account, no action is required.')
            ->salutation('SmartBarangay · Barangay Calayo, Nasugbu, Batangas'));
        ResetPassword::toMailUsing(fn ($user, string $token) => (new MailMessage)
            ->subject('Reset your SmartBarangay password')
            ->greeting('Hello '.$user->name.',')
            ->line('We received a password reset request for your SmartBarangay account.')
            ->action('Reset Password', route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]))
            ->line('This secure link expires in '.config('auth.passwords.users.expire').' minutes.')
            ->line('If you did not request this change, you can safely ignore this email.')
            ->salutation('SmartBarangay · Barangay Calayo, Nasugbu, Batangas'));
    }
}
