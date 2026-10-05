@extends('layouts.auth-action')
@section('title', 'Forgot password')
@section('eyebrow', 'FORGOT PASSWORD')
@section('heading', session('status') ? 'Check your email' : 'Reset your password')
@section('description', session('status')
    ? 'If an eligible account exists for that email address, we have sent a password reset link.'
    : 'Enter the email address associated with your SmartBarangay account. We’ll send you a secure password reset link.')
@section('form')
@if(session('status'))
    <div class="auth-info-note"><strong>Your next step</strong><p class="mb-0">Check your Spam or Junk folder too. You may request another link after a short wait.</p></div>
    <a class="btn btn-primary w-100" href="{{ route('login') }}">Back to Sign In</a>
    <a class="auth-secondary-link" href="{{ route('password.request') }}">Request another reset link</a>
@else
    <x-auth-feedback />
    <form method="POST" action="{{ route('password.email') }}" data-auth-form>
        @csrf
        <div class="row g-3">
            <div class="col-12"><x-form-field name="email" label="Email Address" type="email" autocomplete="email" maxlength="255" placeholder="you@example.com" /></div>
            <div class="col-12"><button type="submit" class="btn btn-primary w-100" data-submit-label="Sending…">Send Password Reset Link</button></div>
        </div>
    </form>
    <a class="auth-secondary-link" href="{{ route('login') }}">← Back to Sign In</a>
@endif
<div class="auth-info-note mt-3">
    <strong>Haven't verified your email yet?</strong>
    <p class="mb-0">Password reset is available after email verification. Sign in with your existing password to resend your verification email. If you cannot sign in, contact the Barangay Calayo office for account assistance.</p>
</div>
@endsection
