@extends('layouts.auth-action')
@section('title', 'Verify your email')
@section('eyebrow', 'VERIFY YOUR EMAIL')
@section('heading', 'Check your inbox')
@section('description', 'Check your inbox for a verification link, or request a new one below. Please verify your email before accessing SmartBarangay services.')
@section('form')
<ol class="auth-progress list-unstyled" aria-label="Account setup progress">
    <li><span aria-hidden="true">✓</span> Account created</li>
    <li aria-current="step"><span aria-hidden="true">2</span> Verify email</li>
    <li><span aria-hidden="true">3</span> Access services</li>
</ol>
<x-auth-feedback />
<div class="auth-email-destination"><span>Verification email destination</span><strong>{{ auth()->user()->email }}</strong></div>
@if(auth()->user()->role === \App\Enums\UserRole::Resident)
<p class="auth-action-hint">Wrong email address? <a href="{{ route('verification.email.edit') }}">Change Email Address</a></p>
@endif
<p class="auth-action-hint">Didn't receive the email? Check your Spam or Junk folder, or request another verification link.</p>
@if(auth()->user()->role === \App\Enums\UserRole::Resident)
<p class="auth-action-hint">For security and account maintenance, unverified Resident accounts may be removed {{ \App\Models\User::UNVERIFIED_RETENTION_DAYS }} days after registration.</p>
@endif
<form method="POST" action="{{ route('verification.send') }}" data-auth-form>
    @csrf
    <button type="submit" class="btn btn-primary w-100" data-submit-label="Sending…">Resend Verification Email</button>
</form>
<form method="POST" action="{{ route('logout') }}" class="mt-3" data-auth-form>
    @csrf
    <button type="submit" class="btn btn-outline-primary w-100" data-submit-label="Signing out…">Logout</button>
</form>
@endsection
