@extends('layouts.auth')
@section('title', 'Forgot password')
@section('brand-title', 'Reset your password with a link sent to your email.')
@section('heading', session('status') ? 'Check your email' : 'Reset your password')
@section('subtitle', session('status')
    ? 'If an eligible account exists for that email address, we have sent a password reset link.'
    : 'Enter the email address of your SmartBarangay account. We will send you a secure reset link.')

@section('form')
@if(session('status'))
    <div class="au-alert au-alert-success" role="status"><span>Check your Spam or Junk folder too. You may request another link after a short wait.</span></div>
    <a class="btn btn-primary btn-lg w-100" href="{{ route('login') }}">Back to Sign In</a>
    <p class="au-switch"><a class="au-link" href="{{ route('password.request') }}">Request another reset link</a></p>
@else
    <x-auth-feedback />
    <form method="POST" action="{{ route('password.email') }}" class="au-form" data-auth-form>
        @csrf
        <x-form-field name="email" label="Email Address" type="email" autocomplete="email" maxlength="255" placeholder="you@example.com" />
        <button type="submit" class="btn btn-primary btn-lg w-100 au-submit" data-submit-label="Sending…">Send Password Reset Link</button>
    </form>
    <p class="au-switch">Remembered it? <a class="au-link" href="{{ route('login') }}">Sign in instead</a></p>
@endif
<div class="au-note au-note-muted">
    <x-app-icon name="bell"/>
    <p><strong>Haven't verified your email yet?</strong> Password reset is available after email verification. Sign in with your existing password to resend your verification email. If you cannot sign in, contact the Barangay Calayo office for account assistance.</p>
</div>
@endsection
